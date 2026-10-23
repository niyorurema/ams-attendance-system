<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Contrôle les règles de chevauchement des contrats horaires.
 */
class ContratsHoraireService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function creer(int $tenantId, int $employeId, array $data): array
    {
        $contrat = $this->normaliser($data);
        $conflits = $this->verifierChevauchement($employeId, $contrat['date_debut'], $contrat['date_fin']);
        if ($conflits !== [] && empty($data['cloturer_contrat_existant'])) {
            throw new \DomainException('Un contrat actif chevauche cette période.');
        }
        foreach ($conflits as $conflit) {
            if ((string) $conflit['date_debut'] >= $contrat['date_debut']) {
                throw new \DomainException('Le contrat existant ne peut pas être clôturé avant sa date de début.');
            }
        }
        $this->db->transBegin();
        try {
            foreach ($conflits as $conflit) {
                $this->db->table('employe_contrats_horaire')->where('id', (int) $conflit['id'])->where('tenant_id', $tenantId)
                    ->update(['date_fin' => date('Y-m-d', strtotime($contrat['date_debut'] . ' -1 day')), 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $this->db->table('employe_contrats_horaire')->insert($contrat + ['tenant_id' => $tenantId, 'employe_id' => $employeId, 'created_at' => date('Y-m-d H:i:s')]);
            if ($this->db->transStatus() === false) throw new RuntimeException('Impossible de créer le contrat horaire.');
            $id = (int) $this->db->insertID();
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
        return $this->trouver($id) ?? throw new RuntimeException('Contrat créé introuvable.');
    }

    /** @return array<string, mixed> */
    public function modifier(int $contratId, array $data): array
    {
        $existant = $this->trouver($contratId);
        if ($existant === null) throw new \OutOfBoundsException('Contrat introuvable.');
        $contrat = $this->normaliser(array_merge($existant, $data));
        if ($this->verifierChevauchement((int) $existant['employe_id'], $contrat['date_debut'], $contrat['date_fin'], $contratId) !== []) throw new \DomainException('Un contrat actif chevauche cette période.');
        $this->db->table('employe_contrats_horaire')->where('id', $contratId)->where('tenant_id', (int) $existant['tenant_id'])->update($contrat + ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->trouver($contratId) ?? throw new RuntimeException('Contrat modifié introuvable.');
    }

    public function supprimer(int $contratId): bool
    {
        $this->db->table('employe_contrats_horaire')->where('id', $contratId)->where('deleted_at', null)->update(['deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->db->affectedRows() > 0;
    }

    /** @return array<string, mixed>|null */
    public function getContratActif(int $employeId, string $date): ?array
    {
        $this->validerDate($date);
        return $this->db->table('employe_contrats_horaire')->where('employe_id', $employeId)->where('actif', 1)->where('deleted_at', null)->where('date_debut <=', $date)->groupStart()->where('date_fin', null)->orWhere('date_fin >=', $date)->groupEnd()->orderBy('date_debut', 'DESC')->get()->getRowArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistorique(int $employeId): array
    {
        return $this->db->table('employe_contrats_horaire')->where('employe_id', $employeId)->where('deleted_at', null)->orderBy('date_debut', 'DESC')->orderBy('id', 'DESC')->get()->getResultArray();
    }
    /**
     * Vérifie qu'un nouveau contrat ne chevauche pas un contrat existant.
     *
     * @return array<int, array<string, mixed>> Conflits détectés; vide si aucun.
     */
    public function verifierChevauchement(
        int $employeId,
        string $dateDebut,
        ?string $dateFin = null,
        ?int $excludeContratId = null
    ): array {
        $this->validerDate($dateDebut);
        if ($dateFin !== null) { $this->validerDate($dateFin); if ($dateFin < $dateDebut) throw new \InvalidArgumentException('La date de fin doit suivre la date de début.'); }
        $query = $this->db->table('employe_contrats_horaire')->where('employe_id', $employeId)->where('actif', 1)->where('deleted_at', null)->where('date_debut <=', $dateFin ?? '9999-12-31')->groupStart()->where('date_fin', null)->orWhere('date_fin >=', $dateDebut)->groupEnd();
        if ($excludeContratId !== null) $query->where('id !=', $excludeContratId);
        return $query->orderBy('date_debut', 'ASC')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function trouver(int $contratId): ?array { return $this->db->table('employe_contrats_horaire')->where('id', $contratId)->where('deleted_at', null)->get()->getRowArray(); }

    /** @return array<string, mixed> */
    private function normaliser(array $data): array
    {
        $mode = (string) ($data['mode_travail'] ?? '');
        if (! in_array($mode, ['TEMPS_PLEIN', 'MI_TEMPS_MATIN', 'MI_TEMPS_APRES_MIDI', 'HORAIRE_LIBRE'], true)) throw new \InvalidArgumentException('Mode de travail invalide.');
        $debut = $this->heure($data['heure_debut'] ?? null); $fin = $this->heure($data['heure_fin'] ?? null);
        if ($mode === 'HORAIRE_LIBRE') { $debut = null; $fin = null; } elseif ($debut === null || $fin === null || $debut >= $fin) throw new \InvalidArgumentException('Les heures de début et de fin sont requises et ordonnées.');
        $dateDebut = (string) ($data['date_debut'] ?? ''); $dateFin = ($data['date_fin'] ?? null) ?: null; $this->validerDate($dateDebut); if ($dateFin !== null) { $this->validerDate($dateFin); if ($dateFin < $dateDebut) throw new \InvalidArgumentException('La date de fin doit suivre la date de début.'); }
        $jours = $data['jours_travail'] ?? []; if (is_string($jours)) $jours = json_decode($jours, true); if (! is_array($jours) || $jours === [] || array_diff($jours, [1,2,3,4,5,6,7]) !== []) throw new \InvalidArgumentException('Les jours travaillés doivent être compris entre 1 et 7.');
        return ['mode_travail' => $mode, 'heure_debut' => $debut, 'heure_fin' => $fin, 'jours_travail' => json_encode(array_values(array_unique(array_map('intval', $jours))), JSON_THROW_ON_ERROR), 'date_debut' => $dateDebut, 'date_fin' => $dateFin, 'actif' => isset($data['actif']) ? (int) (bool) $data['actif'] : 1, 'notes' => isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null];
    }
    private function heure(mixed $heure): ?string { if ($heure === null || $heure === '') return null; if (! is_string($heure) || preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $heure) !== 1) throw new \InvalidArgumentException('Format d’heure invalide.'); return strlen($heure) === 5 ? $heure . ':00' : $heure; }
    private function validerDate(string $date): void { $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date); if ($parsed === false || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('La date doit respecter le format AAAA-MM-JJ.'); }
}
