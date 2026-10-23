<?php

namespace Tests\Feature;

use App\Services\CalculPresenceService;
use App\Services\ContratsHoraireService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class CalculOptInEndToEndTest extends CIUnitTestCase
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

    public function testPersisteUneJourneeOptInPresente(): void
    {
        $this->preparerJournee([['CHECK_IN', '07:25:00'], ['CHECK_OUT', '17:35:00']]);

        $id = (new CalculPresenceService(null, $this->attendanceDb))
            ->recalculerJournee($this->employeId, '2026-10-19');
        $projection = $this->attendanceDb->table('pointages_journee')->where('id', $id)->get()->getRowArray();

        $this->assertGreaterThan(0, $id);
        $this->assertSame('OK', $projection['statut_arrivee']);
        $this->assertSame('OK', $projection['statut_depart']);
        $this->assertSame('PRESENT', $projection['statut_global']);
        $this->assertEqualsWithDelta(10.17, (float) $projection['heures_travaillees'], 0.02);
    }

    public function testPersisteUnRetardPourUneArriveeTardive(): void
    {
        $this->preparerJournee([['CHECK_IN', '13:00:00'], ['CHECK_OUT', '17:35:00']]);

        $id = (new CalculPresenceService(null, $this->attendanceDb))
            ->recalculerJournee($this->employeId, '2026-10-19');
        $projection = $this->attendanceDb->table('pointages_journee')->where('id', $id)->get()->getRowArray();

        $this->assertSame('RETARD', $projection['statut_arrivee']);
        $this->assertSame('RETARD', $projection['statut_global']);
    }

    /** @param array<int, array{string, string}> $pointages */
    private function preparerJournee(array $pointages): void
    {
        $this->attendanceDb->table('tenants')
            ->where('id', $this->tenantId)
            ->update(['actif_regles_nouvelles' => 1, 'jours_travailles' => '1,2,3,4,5,6,7']);

        $service = new ContratsHoraireService($this->attendanceDb);
        $service->creer($this->tenantId, $this->employeId, [
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30',
            'heure_fin' => '17:30',
            'jours_travail' => [1, 2, 3, 4, 5, 6, 7],
            'date_debut' => '2026-10-19',
        ]);

        $horodatage = date('Y-m-d H:i:s');
        foreach ([
            ['ARRIVEE', 'LIMITE_TARDIVE', '07:30:00', 10],
            ['DEPART', 'LIMITE_PRECOCE', '17:30:00', 10],
        ] as [$type, $mode, $cible, $tolerance]) {
            $this->attendanceDb->table('tenant_creneaux_pointage')->insert([
                'tenant_id' => $this->tenantId,
                'type_creneau' => $type,
                'mode' => $mode,
                'heure_cible' => $cible,
                'tolerance_minutes' => $tolerance,
                'actif' => 1,
                'ordre_affichage' => 0,
                'created_at' => $horodatage,
            ]);
        }

        foreach ($pointages as [$type, $heure]) {
            $this->attendanceDb->table('pointages')->insert([
                'tenant_id' => $this->tenantId,
                'employe_id' => $this->employeId,
                'badge_id' => (int) $this->attendanceDb->table('employes')->select('badge_id')
                    ->where('id', $this->employeId)->get()->getRowArray()['badge_id'],
                'type_evenement' => $type,
                'date_pointage' => '2026-10-19 ' . $heure,
                'source' => 'IMPORT',
                'statut' => 'VALIDE',
                'created_at' => $horodatage,
            ]);
        }
    }
}
