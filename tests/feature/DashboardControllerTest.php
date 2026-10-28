<?php

namespace Tests\Feature;

use App\Controllers\Api\DashboardController;
use App\Services\DashboardKpiService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use DateTimeImmutable;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class DashboardControllerTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testKpisCalculeLesAbsentsSurLesSeulsEmployesAttendusDuTenant(): void
    {
        $date = (new DateTimeImmutable('now', new \DateTimeZone('Africa/Bujumbura')))->format('Y-m-d');
        $jour = (int) (new DateTimeImmutable($date))->format('N');
        $joursTravail = json_encode([$jour], JSON_THROW_ON_ERROR);
        $maintenant = date('Y-m-d H:i:s');
        $secondEmployeId = $this->creerEmploye($this->tenantId);

        foreach ([$this->employeId, $secondEmployeId] as $employeId) {
            $this->attendanceDb->table('employe_contrats_horaire')->insert([
                'tenant_id' => $this->tenantId,
                'employe_id' => $employeId,
                'mode_travail' => 'TEMPS_PLEIN',
                'heure_debut' => '07:30:00',
                'heure_fin' => '17:30:00',
                'jours_travail' => $joursTravail,
                'date_debut' => $date,
                'date_fin' => null,
                'actif' => 1,
                'created_at' => $maintenant,
            ]);
        }
        $this->attendanceDb->table('pointages_journee')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'date' => $date,
            'source_calcul' => 'CONTRAT',
            'heure_arrivee' => '07:30:00',
            'statut_arrivee' => 'OK',
            'heure_depart' => null,
            'statut_global' => 'INCOMPLET',
            'details' => '{}',
            'calcule_le' => $maintenant,
            'created_at' => $maintenant,
        ]);

        $controller = $this->controller();
        $response = $controller->kpis();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $employes = $payload['data']['employes'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $employes['attendus_aujourdhui']);
        $this->assertSame(1, $employes['presents']);
        $this->assertSame(1, $employes['absents']);
        $this->assertLessThanOrEqual($employes['attendus_aujourdhui'], $employes['absents']);
        $this->assertSame(1, $employes['sans_pointage_depart']);
    }

    public function testJourneeEnCoursNestPasComptabiliseeCommeAbsence(): void
    {
        $stats = DashboardKpiService::calculerPresenceJour(
            [10, 11],
            [
                10 => ['heure_arrivee' => null, 'statut_global' => 'EN_COURS'],
                11 => ['heure_arrivee' => null, 'statut_global' => 'ABSENT'],
            ]
        );

        $this->assertSame(1, $stats['en_cours']);
        $this->assertSame(1, $stats['absents']);
        $this->assertSame(0, $stats['presents']);
    }

    public function testEvolutionAndRecentPointagesUseRawEventsWhenContractsAreNotConfigured(): void
    {
        $date = (new DateTimeImmutable('now', new \DateTimeZone('Africa/Bujumbura')))->format('Y-m-d');
        $jour = (int) (new DateTimeImmutable($date))->format('N');
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['jours_travailles' => (string) $jour]);

        $horodatage = date('Y-m-d H:i:s');
        foreach ([
            ['CHECK_IN', 'RETARD', '08:00:00'],
            ['CHECK_OUT', 'ANORMAL', '17:00:00'],
        ] as [$type, $statut, $heure]) {
            $this->attendanceDb->table('pointages')->insert([
                'tenant_id' => $this->tenantId,
                'employe_id' => $this->employeId,
                'badge_id' => 12345,
                'type_evenement' => $type,
                'statut_calcul' => $statut,
                'date_pointage' => $date . ' ' . $heure,
                'created_at' => $horodatage,
            ]);
        }

        $response = $this->controller()->kpis();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $evolutionAujourdhui = array_values(array_filter(
            $payload['evolution_presence'],
            static fn (array $journee): bool => $journee['date'] === $date
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $evolutionAujourdhui);
        $this->assertSame(1, $evolutionAujourdhui[0]['presents']);
        $this->assertSame(1, $evolutionAujourdhui[0]['retards']);
        $this->assertSame(0, $evolutionAujourdhui[0]['absents']);
        $this->assertSame(1, $evolutionAujourdhui[0]['total']);
        $this->assertCount(2, $payload['pointages_recents']);
        $this->assertContains(
            'CONTRATS_HORAIRES_ABSENTS',
            array_column($payload['alertes'], 'type')
        );
    }

    public function testRawPointagesCountAsPresenceWhenDailyProjectionIsMissing(): void
    {
        $date = (new DateTimeImmutable('now', new \DateTimeZone('Africa/Bujumbura')))->format('Y-m-d');
        $jour = (int) (new DateTimeImmutable($date))->format('N');
        $maintenant = date('Y-m-d H:i:s');
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['jours_travailles' => (string) $jour]);
        $this->attendanceDb->table('employe_contrats_horaire')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30:00',
            'heure_fin' => '17:30:00',
            'jours_travail' => json_encode([$jour], JSON_THROW_ON_ERROR),
            'date_debut' => $date,
            'date_fin' => null,
            'actif' => 1,
            'created_at' => $maintenant,
        ]);
        $this->attendanceDb->table('pointages')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'badge_id' => 12345,
            'type_evenement' => 'CHECK_IN',
            'statut_calcul' => 'RETARD',
            'date_pointage' => $date . ' 08:00:00',
            'created_at' => $maintenant,
        ]);

        $response = $this->controller()->kpis();
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $payload['employes']['attendus_aujourdhui']);
        $this->assertSame(1, $payload['employes']['presents']);
        $this->assertSame(1, $payload['employes']['en_retard']);
        $this->assertSame(0, $payload['employes']['absents']);
    }

    private function controller(): DashboardController
    {
        $tenantId = $this->tenantId;
        $db = $this->attendanceDb;
        $controller = new class($tenantId, $db) extends DashboardController {
            public function __construct(
                private readonly int $testTenantId,
                private readonly \CodeIgniter\Database\BaseConnection $testDb
            ) {
            }

            protected function claims(): ?\stdClass
            {
                return (object) ['role' => 'rh', 'is_super_admin' => false];
            }

            protected function tenantId(?int $requestedTenantId = null): ?int
            {
                return $requestedTenantId ?? $this->testTenantId;
            }

            protected function service(): DashboardKpiService
            {
                return new DashboardKpiService($this->testDb);
            }
        };
        $controller->initController(
            Services::incomingrequest(new App(), false),
            Services::response(new App(), false),
            Services::logger()
        );

        return $controller;
    }
}
