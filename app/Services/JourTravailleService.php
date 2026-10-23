<?php

namespace App\Services;

use App\Models\TenantModel;
use DateTimeImmutable;

class JourTravailleService
{
    protected TenantModel $tenantModel;
    protected JourFerieService $jourFerieService;

    public function __construct(?TenantModel $tenantModel = null, ?JourFerieService $jourFerieService = null)
    {
        $this->tenantModel = $tenantModel ?? new TenantModel();
        $this->jourFerieService = $jourFerieService ?? new JourFerieService();
    }

    /**
     * @return array{travaille:bool,raison:string,jour_ferie:?array<string,mixed>}
     */
    public function verifierJour(int $tenantId, string $date): array
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException('Tenant invalide.');
        }
        $dateJour = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dateJour === false || $dateJour->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date invalide.');
        }

        $tenant = $this->tenantModel->find($tenantId);
        if ($tenant === null) {
            throw new \RuntimeException('Tenant introuvable.');
        }

        $jourFerie = $this->jourFerieService->getJourFerie($tenantId, $date);
        if ($jourFerie !== null) {
            $ferieTravaille = (int) ($jourFerie['travaille'] ?? 0) === 1;

            return [
                'travaille' => $ferieTravaille,
                'raison' => $ferieTravaille ? 'FERIE_TRAVAILLE' : 'FERIE',
                'jour_ferie' => $jourFerie,
            ];
        }

        $joursTravailles = self::normaliserJours($tenant['jours_travailles'] ?? null);
        $jourSemaine = (int) $dateJour->format('N');
        if (! in_array($jourSemaine, $joursTravailles, true)) {
            return ['travaille' => false, 'raison' => 'WEEKEND', 'jour_ferie' => null];
        }

        return ['travaille' => true, 'raison' => 'NORMAL', 'jour_ferie' => null];
    }

    public function estTravaille(int $tenantId, string $date): bool
    {
        return $this->verifierJour($tenantId, $date)['travaille'];
    }

    public function compterJoursTravailles(int $tenantId, string $debut, string $fin): int
    {
        $dateDebut = DateTimeImmutable::createFromFormat('!Y-m-d', $debut);
        $dateFin = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($dateDebut === false || $dateFin === false
            || $dateDebut->format('Y-m-d') !== $debut
            || $dateFin->format('Y-m-d') !== $fin
            || $dateFin < $dateDebut
        ) {
            throw new \InvalidArgumentException('La période est invalide.');
        }

        $nombre = 0;
        for ($date = $dateDebut; $date <= $dateFin; $date = $date->modify('+1 day')) {
            if ($this->estTravaille($tenantId, $date->format('Y-m-d'))) {
                $nombre++;
            }
        }

        return $nombre;
    }

    /** @return array<int, int> */
    public static function normaliserJours(mixed $jours): array
    {
        if (is_string($jours)) {
            $jours = explode(',', $jours);
        }
        if (! is_array($jours)) {
            return [1, 2, 3, 4, 5];
        }

        $resultat = [];
        foreach ($jours as $jour) {
            if ((is_int($jour) || (is_string($jour) && ctype_digit($jour)))
                && (int) $jour >= 1
                && (int) $jour <= 7
            ) {
                $resultat[] = (int) $jour;
            }
        }

        $resultat = array_values(array_unique($resultat));
        sort($resultat);

        return $resultat === [] ? [1, 2, 3, 4, 5] : $resultat;
    }
}
