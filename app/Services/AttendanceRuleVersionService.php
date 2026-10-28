<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

/**
 * Persists immutable attendance-rule snapshots by schedule and effective date.
 */
class AttendanceRuleVersionService
{
    private const PARAMETER_DEFAULTS = [
        'ecart_max_association_minutes' => 360,
        'seuil_heures_sup_minutes' => 0,
        'mode_calcul_heures_sup' => 'ARRONDI',
        'pas_arrondi_heures_sup_minutes' => 15,
        'duree_pause_minimum_minutes' => 15,
        'duree_pause_maximum_minutes' => 130,
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplicable(int $tenantId, string $date, ?int $horaireId): ?array
    {
        if ($horaireId !== null) {
            $version = $this->getLatest($tenantId, $date, $horaireId);
            if ($version !== null) {
                return $version;
            }
        }

        return $this->getLatest($tenantId, $date, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatest(int $tenantId, string $date, ?int $horaireId): ?array
    {
        $query = $this->db->table('tenant_regles_pointage_versions')
            ->where('tenant_id', $tenantId)
            ->where('date_effet <=', $date);
        $horaireId === null ? $query->where('horaire_id', null) : $query->where('horaire_id', $horaireId);
        $row = $query
            ->orderBy('date_effet', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->decoder($row);
    }

    /**
     * @param array<string, mixed> $parametres
     * @param array<int, array<string, mixed>> $creneaux
     * @return array<string, mixed>
     */
    public function enregistrer(
        int $tenantId,
        ?int $horaireId,
        string $dateEffet,
        bool $actif,
        array $parametres,
        array $creneaux
    ): array {
        if ($tenantId < 1 || ! $this->dateValide($dateEffet)) {
            throw new \InvalidArgumentException('Tenant ou date d’effet invalide.');
        }
        $configuration = $this->preparer($tenantId, $horaireId, $actif, $parametres, $creneaux);
        if ($actif && ! $this->contientCreneauxPause($configuration['creneaux']) && $configuration['pointages_pause_observes']) {
            throw new \InvalidArgumentException(
                'Des pointages de pause ont été observés récemment. Configurez les deux créneaux de pause avant l’activation.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $this->db->transBegin();
        try {
            $this->db->table('tenant_regles_pointage_versions')->insert([
                'tenant_id' => $tenantId,
                'horaire_id' => $horaireId,
                'date_effet' => $dateEffet,
                'actif' => $actif ? 1 : 0,
                'parametres' => json_encode($configuration['parametres'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'creneaux' => json_encode($configuration['creneaux'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            if ($this->db->transStatus() === false || $id < 1) {
                throw new RuntimeException('La sauvegarde de la version des règles de pointage a échoué.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        $row = $this->db->table('tenant_regles_pointage_versions')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new RuntimeException('La version enregistrée est introuvable.');
        }

        return $this->decoder($row);
    }

    /**
     * @param array<string, mixed> $parametres
     * @param array<int, array<string, mixed>> $creneaux
     * @return array{parametres:array<string,int|string>,creneaux:array<int,array<string,mixed>>,pointages_pause_observes:bool}
     */
    public function preparer(
        int $tenantId,
        ?int $horaireId,
        bool $actif,
        array $parametres,
        array $creneaux
    ): array {
        if ($tenantId < 1 || ($horaireId !== null && ! $this->horaireAppartientAuTenant($tenantId, $horaireId))) {
            throw new \InvalidArgumentException('Tenant ou horaire invalide pour le profil de règles.');
        }
        $parametresNormalises = $this->normaliserParametres($parametres);
        $creneauxNormalises = $this->normaliserCreneaux($creneaux);
        if ($actif && $creneauxNormalises === []) {
            throw new \InvalidArgumentException('Activez au moins un créneau avant d’activer les nouvelles règles.');
        }
        if ($actif && $horaireId !== null) {
            $this->validerCompatibiliteHoraire($tenantId, $horaireId, $creneauxNormalises);
        }

        return [
            'parametres' => $parametresNormalises,
            'creneaux' => $creneauxNormalises,
            'pointages_pause_observes' => $this->pointagesPauseRecents($tenantId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getHoraires(int $tenantId): array
    {
        $rows = $this->db->table('tenant_horaires')
            ->select('id, nom, plages_horaires, heures_travail_normales, est_defaut')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('est_defaut', 'DESC')
            ->orderBy('nom', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['est_defaut'] = (bool) $row['est_defaut'];
            $row['plages_horaires'] = $this->decodeJsonArray($row['plages_horaires'] ?? null);
            $row['heures_travail_normales'] = (float) $row['heures_travail_normales'];
        }
        unset($row);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistorique(int $tenantId, ?int $horaireId = null, int $limit = 30): array
    {
        $query = $this->db->table('tenant_regles_pointage_versions v')
            ->select('v.id, v.horaire_id, h.nom AS horaire_nom, v.date_effet, v.actif, v.created_at')
            ->join('tenant_horaires h', 'h.id = v.horaire_id AND h.tenant_id = v.tenant_id', 'left')
            ->where('v.tenant_id', $tenantId);
        $query->groupStart()
            ->where('v.horaire_id', $horaireId)
            ->orWhere('v.horaire_id', null)
            ->groupEnd();

        return $query
            ->orderBy('v.date_effet', 'DESC')
            ->orderBy('v.id', 'DESC')
            ->limit(min(100, max(1, $limit)))
            ->get()
            ->getResultArray();
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function decoder(array $row): array
    {
        $parametres = json_decode((string) $row['parametres'], true, 512, JSON_THROW_ON_ERROR);
        $creneaux = json_decode((string) $row['creneaux'], true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($parametres) || ! is_array($creneaux)) {
            throw new RuntimeException('Une version de règles de pointage contient un JSON invalide.');
        }

        return [
            'id' => (int) $row['id'],
            'horaire_id' => $row['horaire_id'] === null ? null : (int) $row['horaire_id'],
            'date_effet' => (string) $row['date_effet'],
            'actif' => (bool) $row['actif'],
            'parametres' => $parametres,
            'creneaux' => $creneaux,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $parametres
     *  @return array<string, int|string>
     */
    private function normaliserParametres(array $parametres): array
    {
        $normalises = self::PARAMETER_DEFAULTS;
        foreach ($parametres as $cle => $valeur) {
            if (! array_key_exists($cle, $normalises) || is_array($valeur) || is_object($valeur) || $valeur === null) {
                throw new \InvalidArgumentException('Paramètre de pointage inconnu ou invalide.');
            }
            if ($cle === 'mode_calcul_heures_sup') {
                if (! is_string($valeur) || ! in_array($valeur, ['ARRONDI', 'EXACT'], true)) {
                    throw new \InvalidArgumentException('Le mode des heures supplémentaires doit être ARRONDI ou EXACT.');
                }
                $normalises[$cle] = $valeur;
                continue;
            }

            $entier = filter_var($valeur, FILTER_VALIDATE_INT);
            $minimum = $cle === 'pas_arrondi_heures_sup_minutes' ? 1 : 0;
            if ($entier === false || $entier < $minimum) {
                throw new \InvalidArgumentException('Une valeur numérique des paramètres de pointage est invalide.');
            }
            $normalises[$cle] = $entier;
        }
        if ($normalises['duree_pause_minimum_minutes'] > $normalises['duree_pause_maximum_minutes']) {
            throw new \InvalidArgumentException('La durée minimale de pause ne peut pas dépasser sa durée maximale.');
        }

        return $normalises;
    }

    /** @param array<int, array<string, mixed>> $creneaux
     *  @return array<int, array<string, mixed>>
     */
    private function normaliserCreneaux(array $creneaux): array
    {
        $normalises = [];
        $types = ['ARRIVEE', 'DEBUT_PAUSE', 'FIN_PAUSE', 'DEPART'];
        foreach ($creneaux as $creneau) {
            if (! is_array($creneau)
                || ! in_array($creneau['type_creneau'] ?? null, $types, true)
                || ! in_array($creneau['mode'] ?? null, ['LIMITE_TARDIVE', 'LIMITE_PRECOCE', 'PLAGE'], true)
                || isset($normalises[$creneau['type_creneau']])
                || (array_key_exists('notes', $creneau) && $creneau['notes'] !== null && ! is_string($creneau['notes']))
            ) {
                throw new \InvalidArgumentException('Liste de créneaux invalide ou contenant un type en double.');
            }

            $mode = (string) $creneau['mode'];
            $heure = $this->heure($creneau['heure_cible'] ?? null);
            $debut = $this->heure($creneau['plage_debut'] ?? null);
            $fin = $this->heure($creneau['plage_fin'] ?? null);
            if (($mode === 'PLAGE' && ($debut === null || $fin === null || $debut >= $fin))
                || ($mode !== 'PLAGE' && $heure === null)
            ) {
                throw new \InvalidArgumentException('Les heures du créneau sont incompatibles avec son mode.');
            }
            $tolerance = $creneau['tolerance_minutes'] ?? null;
            $ordre = $creneau['ordre_affichage'] ?? count($normalises);
            if (($tolerance !== null && (filter_var($tolerance, FILTER_VALIDATE_INT) === false || (int) $tolerance < 0))
                || filter_var($ordre, FILTER_VALIDATE_INT) === false
                || (int) $ordre < 0
            ) {
                throw new \InvalidArgumentException('Tolérance ou ordre de créneau invalide.');
            }

            $normalises[$creneau['type_creneau']] = [
                'type_creneau' => $creneau['type_creneau'],
                'mode' => $mode,
                'heure_cible' => $mode === 'PLAGE' ? null : $heure,
                'tolerance_minutes' => $tolerance === null ? null : (int) $tolerance,
                'plage_debut' => $mode === 'PLAGE' ? $debut : null,
                'plage_fin' => $mode === 'PLAGE' ? $fin : null,
                'ordre_affichage' => (int) $ordre,
                'notes' => isset($creneau['notes']) && is_string($creneau['notes']) ? $creneau['notes'] : null,
            ];
        }

        return array_values($normalises);
    }

    /** @param array<int, array<string, mixed>> $creneaux */
    private function validerCompatibiliteHoraire(int $tenantId, int $horaireId, array $creneaux): void
    {
        $row = $this->db->table('tenant_horaires')
            ->select('plages_horaires')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($row === null) {
            throw new \InvalidArgumentException('Horaire actif introuvable pour ce tenant.');
        }
        $evenements = array_column($this->decodeJsonArray($row['plages_horaires']), 'type');
        $mapping = [
            'ARRIVEE' => 'CHECK_IN',
            'DEBUT_PAUSE' => 'BREAK_OUT',
            'FIN_PAUSE' => 'BREAK_IN',
            'DEPART' => 'CHECK_OUT',
        ];
        foreach ($creneaux as $creneau) {
            if (! in_array($mapping[$creneau['type_creneau']], $evenements, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Le créneau « %s » ne correspond à aucune plage de l’horaire sélectionné.',
                    $creneau['type_creneau']
                ));
            }
        }
    }

    /** @param array<int, array<string, mixed>> $creneaux */
    private function contientCreneauxPause(array $creneaux): bool
    {
        $types = array_column($creneaux, 'type_creneau');

        return in_array('DEBUT_PAUSE', $types, true) && in_array('FIN_PAUSE', $types, true);
    }

    private function pointagesPauseRecents(int $tenantId): bool
    {
        return $this->db->table('pointages')
            ->where('tenant_id', $tenantId)
            ->whereIn('type_evenement', ['BREAK_IN', 'BREAK_OUT'])
            ->where('statut', 'VALIDE')
            ->where('deleted_at', null)
            ->where('date_pointage >=', date('Y-m-d 00:00:00', strtotime('-30 days')))
            ->countAllResults() > 0;
    }

    private function horaireAppartientAuTenant(int $tenantId, int $horaireId): bool
    {
        return $this->db->table('tenant_horaires')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->countAllResults() === 1;
    }

    private function heure(mixed $heure): ?string
    {
        if ($heure === null || $heure === '') {
            return null;
        }
        if (! is_string($heure) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $heure) !== 1) {
            throw new \InvalidArgumentException('Format d’heure invalide.');
        }

        return strlen($heure) === 5 ? $heure . ':00' : $heure;
    }

    private function dateValide(string $date): bool
    {
        $valeur = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $valeur !== false && $valeur->format('Y-m-d') === $date;
    }

    /** @return array<int, array<string, mixed>> */
    private function decodeJsonArray(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
