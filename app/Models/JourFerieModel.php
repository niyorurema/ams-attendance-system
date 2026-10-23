<?php

namespace App\Models;

use CodeIgniter\Model;

class JourFerieModel extends Model
{
    protected $table = 'jours_feries';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'date',
        'nom',
        'type',
        'recurrent',
        'travaille',
        'description',
        'actif',
    ];

    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'date' => 'required|valid_date',
        'nom' => 'required|max_length[150]',
        'type' => 'required|in_list[NATIONAL,RELIGIEUX,PERSONNALISE,CONGE]',
        'recurrent' => 'permit_empty|in_list[0,1]',
        'travaille' => 'permit_empty|in_list[0,1]',
        'description' => 'permit_empty|max_length[2000]',
        'actif' => 'required|in_list[0,1]',
    ];

    public function estJourFerie(int $tenantId, string $date): ?array
    {
        $moisJour = (new \DateTimeImmutable($date))->format('m-d');
        $jours = $this->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->groupStart()
                ->where('date', $date)
                ->orGroupStart()
                    ->where('recurrent', 1)
                    ->where('MONTH(date) =', (int) substr($moisJour, 0, 2))
                    ->where('DAY(date) =', (int) substr($moisJour, 3, 2))
                ->groupEnd()
            ->groupEnd()
            ->orderBy('date', 'DESC')
            ->findAll();

        foreach ($jours as $jour) {
            if ($jour['date'] === $date) {
                return $jour;
            }
        }

        return $jours[0] ?? null;
    }

    public function getPourPeriode(int $tenantId, string $debut, string $fin, bool $actifsSeulement = true): array
    {
        $requete = $this->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->groupStart()
                ->where('date >=', $debut)
                ->where('date <=', $fin)
                ->orGroupStart()
                    ->where('recurrent', 1)
                ->groupEnd()
            ->groupEnd()
            ->orderBy('date', 'ASC');
        if ($actifsSeulement) {
            $requete->where('actif', 1);
        }
        $jours = $requete->findAll();

        $dateDebut = new \DateTimeImmutable($debut);
        $dateFin = new \DateTimeImmutable($fin);
        $occurrences = [];
        for ($date = $dateDebut; $date <= $dateFin; $date = $date->modify('+1 day')) {
            $dateTexte = $date->format('Y-m-d');
            $moisJour = $date->format('m-d');
            foreach ($jours as $jour) {
                $dateSource = new \DateTimeImmutable($jour['date']);
                if ($jour['date'] !== $dateTexte
                    && ((int) $jour['recurrent'] !== 1 || $dateSource->format('m-d') !== $moisJour)
                ) {
                    continue;
                }

                $cle = $dateTexte;
                if (! isset($occurrences[$cle]) || $jour['date'] === $dateTexte) {
                    $jour['date_occurrence'] = $dateTexte;
                    $occurrences[$cle] = $jour;
                }
            }
        }

        return array_values($occurrences);
    }
}
