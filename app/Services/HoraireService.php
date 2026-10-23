<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Gère les horaires des tenants et leurs affectations aux employés.
 */
class HoraireService
{
    /** Connexion à la base de données applicative. */
    protected BaseConnection $db;

    /**
     * @param ?BaseConnection $db Connexion injectée, utile pour les tests.
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Retourne l'horaire applicable à un employé pour une date donnée.
     *
     * Une affectation couvrant la date est prioritaire sur l'horaire par défaut
     * du tenant. Les plages JSON sont toujours retournées sous forme de tableau.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param string $date Date au format Y-m-d.
     * @return array<string, mixed> Horaire applicable ou tableau vide.
     */
    public function getHoraireEmploye(int $employeId, string $date): array
    {
        $dateNormalisee = $this->normaliserDate($date);
        $employe = $this->db->table('employes')
            ->select('id, tenant_id')
            ->where('id', $employeId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($employe === null) {
            return [];
        }

        $horaire = $this->db->table('employe_horaire eh')
            ->select('th.*')
            ->join('tenant_horaires th', 'th.id = eh.horaire_id')
            ->where('eh.tenant_id', (int) $employe['tenant_id'])
            ->where('eh.employe_id', $employeId)
            ->where('eh.date_debut <=', $dateNormalisee)
            ->groupStart()
            ->where('eh.date_fin', null)
            ->orWhere('eh.date_fin >=', $dateNormalisee)
            ->groupEnd()
            ->where('th.actif', 1)
            ->where('th.deleted_at', null)
            ->orderBy('eh.date_debut', 'DESC')
            ->get()
            ->getRowArray();

        if ($horaire === null) {
            $horaire = $this->db->table('tenant_horaires')
                ->where('tenant_id', (int) $employe['tenant_id'])
                ->where('est_defaut', 1)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
        }

        if ($horaire === null) {
            return [];
        }

        $horaire['plages_horaires'] = $this->decoderPlages($horaire['plages_horaires'] ?? '[]');

        return $horaire;
    }

    /**
     * Crée ou retourne un horaire par défaut prédéfini pour un tenant.
     *
     * @param int $tenantId Identifiant du tenant.
     * @param string $type OBR, STANDARD ou FLEXIBLE.
     * @return int Identifiant de l'horaire créé ou existant.
     */
    public function creerHoraireDefaut(int $tenantId, string $type = 'STANDARD'): int
    {
        $definition = $this->getDefinitionParDefaut($type);
        $existant = $this->db->table('tenant_horaires')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('nom', $definition['nom'])
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($existant !== null) {
            return (int) $existant['id'];
        }

        $this->db->transStart();
        $this->db->table('tenant_horaires')
            ->where('tenant_id', $tenantId)
            ->update(['est_defaut' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->db->table('tenant_horaires')->insert([
            'tenant_id' => $tenantId,
            'nom' => $definition['nom'],
            'description' => $definition['description'],
            'nb_pointages_attendus' => count($definition['plages']),
            'plages_horaires' => json_encode($definition['plages'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'jours_travailles' => '1,2,3,4,5',
            'heures_travail_normales' => $definition['heures_normales'],
            'seuil_heures_anormal' => $definition['seuil_anormal'],
            'est_defaut' => 1,
            'actif' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $horaireId = (int) $this->db->insertID();
        $this->db->transComplete();

        if ($this->db->transStatus() === false || $horaireId < 1) {
            throw new \RuntimeException('Impossible de créer l’horaire par défaut.');
        }

        return $horaireId;
    }

    /**
     * Active un horaire existant comme défaut pour son tenant.
     */
    public function definirHoraireParDefaut(int $tenantId, int $horaireId): void
    {
        $horaire = $this->db->table('tenant_horaires')
            ->select('id')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($horaire === null) {
            throw new \InvalidArgumentException('Horaire actif introuvable pour ce tenant.');
        }

        $this->db->transBegin();
        $this->db->table('tenant_horaires')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->update(['est_defaut' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->db->table('tenant_horaires')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->update(['est_defaut' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

        if ($this->db->transStatus() === false || $this->db->affectedRows() !== 1) {
            $this->db->transRollback();
            throw new \RuntimeException('Impossible d’activer l’horaire par défaut.');
        }
        $this->db->transCommit();
    }

    /**
     * Met à jour un horaire existant sans modifier ses affectations individuelles.
     *
     * @param array{nom:string,description:?string,plages_horaires:array<int,array<string,mixed>>,heures_travail_normales:float,seuil_heures_anormal:float} $donnees
     * @return array<string,mixed>
     */
    public function modifierHoraireTenant(int $tenantId, int $horaireId, array $donnees): array
    {
        if ($tenantId < 1 || $horaireId < 1) {
            throw new \InvalidArgumentException('Tenant ou horaire invalide.');
        }
        $horaire = $this->db->table('tenant_horaires')
            ->select('id')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($horaire === null) {
            throw new \InvalidArgumentException('Horaire actif introuvable pour ce tenant.');
        }

        $nom = trim($donnees['nom']);
        $heuresNormales = $donnees['heures_travail_normales'];
        $seuilAnormal = $donnees['seuil_heures_anormal'];
        if ($nom === '' || mb_strlen($nom) > 100
            || ! is_finite($heuresNormales) || $heuresNormales <= 0 || $heuresNormales > 24
            || ! is_finite($seuilAnormal) || $seuilAnormal < 0 || $seuilAnormal > $heuresNormales
            || ! $this->validerPlagesHoraires($donnees['plages_horaires'])
        ) {
            throw new \InvalidArgumentException('Les paramètres de l’horaire sont invalides.');
        }

        $this->db->table('tenant_horaires')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->update([
                'nom' => $nom,
                'description' => $donnees['description'] !== null ? trim($donnees['description']) : null,
                'plages_horaires' => json_encode($donnees['plages_horaires'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'nb_pointages_attendus' => count($donnees['plages_horaires']),
                'heures_travail_normales' => $heuresNormales,
                'seuil_heures_anormal' => $seuilAnormal,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Impossible d’enregistrer les paramètres de l’horaire.');
        }

        $resultat = $this->db->table('tenant_horaires')
            ->where('id', $horaireId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();
        if ($resultat === null) {
            throw new \RuntimeException('L’horaire modifié est introuvable.');
        }
        $resultat['plages_horaires'] = $this->decoderPlages($resultat['plages_horaires']);

        return $resultat;
    }

    /**
     * Vérifie le format et la cohérence de plages horaires.
     *
     * Chaque plage doit posséder un type d'événement et deux heures au format
     * HH:MM. Deux plages ne peuvent pas se chevaucher.
     *
     * @param array<int, array<string, mixed>> $plages Plages à contrôler.
     */
    public function validerPlagesHoraires(array $plages): bool
    {
        if ($plages === []) {
            return false;
        }

        $intervalles = [];
        $typesAutorises = ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT'];
        foreach ($plages as $plage) {
            if (! isset($plage['type'], $plage['debut'], $plage['fin'])
                || ! in_array($plage['type'], $typesAutorises, true)
                || ! is_string($plage['debut'])
                || ! is_string($plage['fin'])
            ) {
                return false;
            }
            if (isset($plage['tolerance_min'])
                && (filter_var($plage['tolerance_min'], FILTER_VALIDATE_INT) === false || (int) $plage['tolerance_min'] < 0)
            ) {
                return false;
            }

            $debut = $this->convertirHeureEnMinutes($plage['debut']);
            $fin = $this->convertirHeureEnMinutes($plage['fin']);
            if ($debut === null || $fin === null || $debut >= $fin) {
                return false;
            }

            $intervalles[] = ['debut' => $debut, 'fin' => $fin];
        }

        usort($intervalles, static fn (array $gauche, array $droite): int => $gauche['debut'] <=> $droite['debut']);
        foreach ($intervalles as $index => $intervalle) {
            if ($index > 0 && $intervalle['debut'] < $intervalles[$index - 1]['fin']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{nom:string,description:string,plages:array<int,array<string,string>>,heures_normales:float,seuil_anormal:float}
     */
    protected function getDefinitionParDefaut(string $type): array
    {
        $definitions = [
            'OBR' => [
                'nom' => 'Standard OBR',
                'description' => 'Horaire OBR avec quatre pointages quotidiens.',
                'plages' => [
                    ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15', 'tolerance_min' => 10],
                    ['type' => 'BREAK_OUT', 'debut' => '12:00', 'fin' => '12:30', 'tolerance_min' => 10],
                    ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '14:00', 'tolerance_min' => 10],
                    ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30', 'tolerance_min' => 10],
                ],
                'heures_normales' => 8.00,
                'seuil_anormal' => 6.00,
            ],
            'STANDARD' => [
                'nom' => 'Standard',
                'description' => 'Horaire standard avec arrivée et départ.',
                'plages' => [
                    ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15', 'tolerance_min' => 10],
                    ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30', 'tolerance_min' => 10],
                ],
                'heures_normales' => 8.00,
                'seuil_anormal' => 6.00,
            ],
            'FLEXIBLE' => [
                'nom' => 'Flexible',
                'description' => 'Horaire flexible avec deux pointages quotidiens.',
                'plages' => [
                    ['type' => 'CHECK_IN', 'debut' => '06:00', 'fin' => '10:00', 'tolerance_min' => 10],
                    ['type' => 'CHECK_OUT', 'debut' => '15:00', 'fin' => '21:00', 'tolerance_min' => 10],
                ],
                'heures_normales' => 8.00,
                'seuil_anormal' => 6.00,
            ],
        ];

        $type = strtoupper(trim($type));
        if (! isset($definitions[$type])) {
            throw new \InvalidArgumentException('Le type d’horaire doit être OBR, STANDARD ou FLEXIBLE.');
        }

        return $definitions[$type];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function decoderPlages(mixed $plages): array
    {
        if (is_array($plages)) {
            return $plages;
        }

        if (! is_string($plages)) {
            return [];
        }

        $decodees = json_decode($plages, true);

        return is_array($decodees) ? $decodees : [];
    }

    /** Normalise une date utilisateur au format Y-m-d. */
    protected function normaliserDate(string $date): string
    {
        $dateNormalisee = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dateNormalisee === false || $dateNormalisee->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('La date doit respecter le format Y-m-d.');
        }

        return $date;
    }

    /** Convertit une heure HH:MM en minutes depuis minuit. */
    protected function convertirHeureEnMinutes(string $heure): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!H:i', $heure);
        if ($date === false || $date->format('H:i') !== $heure) {
            return null;
        }

        return ((int) $date->format('H') * 60) + (int) $date->format('i');
    }
}
