<?php

namespace App\Services;

use App\Models\JourFerieModel;

class JourFerieService
{
    protected JourFerieModel $model;

    public function __construct(?JourFerieModel $model = null)
    {
        $this->model = $model ?? new JourFerieModel();
    }

    public function getJourFerie(int $tenantId, string $date): ?array
    {
        return $this->model->estJourFerie($tenantId, $date);
    }

    public function estFerie(int $tenantId, string $date): bool
    {
        return $this->getJourFerie($tenantId, $date) !== null;
    }

    public function estFerieTravaille(int $tenantId, string $date): bool
    {
        $jourFerie = $this->getJourFerie($tenantId, $date);
        return $jourFerie !== null && (int) ($jourFerie['travaille'] ?? 0) === 1;
    }

    /** @return array<int, array<string, mixed>> */
    public function getMois(int $tenantId, int $annee, int $mois): array
    {
        if ($tenantId < 1 || $annee < 2000 || $annee > 2100 || $mois < 1 || $mois > 12) {
            throw new \InvalidArgumentException('Tenant, année ou mois invalide.');
        }

        $debut = sprintf('%04d-%02d-01', $annee, $mois);
        $fin = date('Y-m-t', strtotime($debut));

        return $this->model->getPourPeriode($tenantId, $debut, $fin);
    }

    public function importerJoursFeriesBurundi(int $tenantId, int $annee): int
    {
        if ($tenantId < 1 || $annee < 2000 || $annee > 2100) {
            throw new \InvalidArgumentException('Tenant ou année invalide.');
        }

        $joursFeries = [
            ['date' => sprintf('%04d-01-01', $annee), 'nom' => 'Nouvel An', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-02-05', $annee), 'nom' => 'Journée de l\'Unité', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-04-06', $annee), 'nom' => 'Journée du Président Ntaryamira', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-05-01', $annee), 'nom' => 'Fête du Travail', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-07-01', $annee), 'nom' => 'Fête de l\'Indépendance', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-08-15', $annee), 'nom' => 'Assomption', 'type' => 'RELIGIEUX'],
            ['date' => sprintf('%04d-10-13', $annee), 'nom' => 'Assassinat du Prince Rwagasore', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-10-21', $annee), 'nom' => 'Assassinat du Président Ndadaye', 'type' => 'NATIONAL'],
            ['date' => sprintf('%04d-11-01', $annee), 'nom' => 'Toussaint', 'type' => 'RELIGIEUX'],
            ['date' => sprintf('%04d-12-25', $annee), 'nom' => 'Noël', 'type' => 'RELIGIEUX'],
        ];

        $count = 0;
        foreach ($joursFeries as $jour) {
            $existe = $this->model
                ->where('tenant_id', $tenantId)
                ->where('date', $jour['date'])
                ->where('deleted_at', null)
                ->first();

            if ($existe === null) {
                if (! $this->model->insert([
                    'tenant_id' => $tenantId,
                    'date' => $jour['date'],
                    'nom' => $jour['nom'],
                    'type' => $jour['type'],
                    'recurrent' => 1,
                    'travaille' => 0,
                    'actif' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ])) {
                    throw new \RuntimeException('Impossible d’importer le jour férié ' . $jour['date'] . '.');
                }
                $count++;
            }
        }

        return $count;
    }
}
