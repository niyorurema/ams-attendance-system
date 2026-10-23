<?php

use App\Models\TenantModel;
use App\Services\JourFerieService;
use App\Services\JourTravailleService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class JourTravailleServiceTest extends CIUnitTestCase
{
    public function testReconnaîtUnJourOuvréEtUnJourDeRepos(): void
    {
        $service = $this->creerService('1,2,3,4,5');

        $this->assertSame('NORMAL', $service->verifierJour(1, '2026-10-02')['raison']);
        $this->assertSame('WEEKEND', $service->verifierJour(1, '2026-10-03')['raison']);
        $this->assertFalse($service->estTravaille(1, '2026-10-03'));
    }

    public function testJourFeriePrimeSurLaConfigurationHebdomadaire(): void
    {
        $service = $this->creerService('1,2,3,4,5', [
            '2026-10-03' => ['nom' => 'Jour férié travaillé', 'travaille' => 1],
            '2026-10-04' => ['nom' => 'Jour férié', 'travaille' => 0],
        ]);

        $this->assertSame('FERIE_TRAVAILLE', $service->verifierJour(1, '2026-10-03')['raison']);
        $this->assertSame('FERIE', $service->verifierJour(1, '2026-10-04')['raison']);
    }

    public function testCompteLesJoursTravaillesEnIncluantLesBordsEtEnExcluantLesFeries(): void
    {
        $service = $this->creerService('1,2,3,4,5', [
            '2026-10-09' => ['nom' => 'Férié', 'travaille' => 0],
        ]);

        $this->assertSame(4, $service->compterJoursTravailles(1, '2026-10-05', '2026-10-11'));
    }

    public function testRefuseUnePeriodeInvalide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->creerService('1,2,3,4,5')->compterJoursTravailles(1, '2026-10-10', '2026-10-01');
    }

    private function creerService(string $jours, array $feries = []): JourTravailleService
    {
        return new JourTravailleService(
            new class(['jours_travailles' => $jours]) extends TenantModel {
                public function __construct(private array $tenant)
                {
                }

                public function find($id = null)
                {
                    return $this->tenant;
                }
            },
            new class($feries) extends JourFerieService {
                public function __construct(private array $feries)
                {
                }

                public function getJourFerie(int $tenantId, string $date): ?array
                {
                    return $this->feries[$date] ?? null;
                }
            }
        );
    }
}
