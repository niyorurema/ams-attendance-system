<?php

namespace Tests\Feature;

use App\Services\AttendanceRuleVersionService;
use App\Services\CalculPresenceService;
use App\Services\HoraireService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use Tests\Support\AttendanceDatabaseTestTrait;

/**
 * @internal
 */
final class AttendanceRuleVersionServiceTest extends CIUnitTestCase
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

    public function testLaVersionDeHoraireRemplaceLeRepliGlobalUniquementDepuisSaDateEffet(): void
    {
        $horaireId = (new HoraireService($this->attendanceDb))->creerHoraireDefaut($this->tenantId, 'STANDARD');
        $service = new AttendanceRuleVersionService($this->attendanceDb);
        $service->enregistrer($this->tenantId, null, '2026-10-01', false, [], []);
        $profile = $service->enregistrer(
            $this->tenantId,
            $horaireId,
            '2026-10-20',
            true,
            [],
            $this->creneauxStandard()
        );

        $avant = $service->getApplicable($this->tenantId, '2026-10-19', $horaireId);
        $apres = $service->getApplicable($this->tenantId, '2026-10-20', $horaireId);

        $this->assertNotNull($avant);
        $this->assertFalse($avant['actif']);
        $this->assertNotNull($apres);
        $this->assertTrue($apres['actif']);
        $this->assertSame($profile['id'], $apres['id']);
        $this->assertCount(2, $service->getHistorique($this->tenantId, $horaireId));
    }

    public function testNePeutPasActiverUnProfilSansCreneau(): void
    {
        $service = new AttendanceRuleVersionService($this->attendanceDb);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Activez au moins un créneau');
        $service->enregistrer($this->tenantId, null, '2026-10-20', true, [], []);
    }

    public function testRefuseUnCreneauAbsentDeLHoraireAssocie(): void
    {
        $horaireId = (new HoraireService($this->attendanceDb))->creerHoraireDefaut($this->tenantId, 'STANDARD');
        $service = new AttendanceRuleVersionService($this->attendanceDb);
        $creneauPause = [[
            'type_creneau' => 'DEBUT_PAUSE',
            'mode' => 'PLAGE',
            'plage_debut' => '12:00',
            'plage_fin' => '12:30',
        ]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ne correspond à aucune plage');
        $service->enregistrer($this->tenantId, $horaireId, '2026-10-20', true, [], $creneauPause);
    }

    public function testBloqueActivationSiDesPointagesPauseSontConnusSansLesDeuxCreneaux(): void
    {
        $this->attendanceDb->table('pointages')->insert([
            'tenant_id' => $this->tenantId,
            'employe_id' => $this->employeId,
            'badge_id' => (int) $this->attendanceDb->table('employes')
                ->select('badge_id')
                ->where('id', $this->employeId)
                ->get()
                ->getRowArray()['badge_id'],
            'type_evenement' => 'BREAK_OUT',
            'date_pointage' => date('Y-m-d H:i:s'),
            'source' => 'IMPORT',
            'statut' => 'VALIDE',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $service = new AttendanceRuleVersionService($this->attendanceDb);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pointages de pause ont été observés');
        $service->enregistrer(
            $this->tenantId,
            null,
            '2026-10-27',
            true,
            [],
            [$this->creneauxStandard()[0]]
        );
    }

    public function testLeCalculUtiliseLeProfilHoraireMemeSiLeCommutateurHistoriqueEstDesactive(): void
    {
        $horaireId = (new HoraireService($this->attendanceDb))->creerHoraireDefaut($this->tenantId, 'STANDARD');
        $service = new AttendanceRuleVersionService($this->attendanceDb);
        $version = $service->enregistrer(
            $this->tenantId,
            $horaireId,
            '2026-10-19',
            true,
            [],
            $this->creneauxStandard()
        );
        $horodatage = date('Y-m-d H:i:s');
        foreach ([['CHECK_IN', '07:45:00'], ['CHECK_OUT', '17:00:00']] as [$type, $heure]) {
            $this->attendanceDb->table('pointages')->insert([
                'tenant_id' => $this->tenantId,
                'employe_id' => $this->employeId,
                'badge_id' => (int) $this->attendanceDb->table('employes')
                    ->select('badge_id')
                    ->where('id', $this->employeId)
                    ->get()
                    ->getRowArray()['badge_id'],
                'type_evenement' => $type,
                'date_pointage' => '2026-10-19 ' . $heure,
                'source' => 'IMPORT',
                'statut' => 'VALIDE',
                'created_at' => $horodatage,
            ]);
        }

        $resultat = (new CalculPresenceService(null, $this->attendanceDb))
            ->interpreterJournee($this->employeId, '2026-10-19');

        $this->assertSame('PRESENT', $resultat['statut_global']);
        $this->assertSame($version['id'], $resultat['details']['regle_version_id']);
        $this->assertSame($horaireId, $resultat['details']['horaire_id']);
        $this->assertSame('Standard', $resultat['details']['horaire_nom']);
    }

    /** @return array<int, array<string, mixed>> */
    private function creneauxStandard(): array
    {
        return [
            [
                'type_creneau' => 'ARRIVEE',
                'mode' => 'PLAGE',
                'plage_debut' => '07:30',
                'plage_fin' => '08:15',
            ],
            [
                'type_creneau' => 'DEPART',
                'mode' => 'PLAGE',
                'plage_debut' => '16:30',
                'plage_fin' => '17:30',
            ],
        ];
    }
}
