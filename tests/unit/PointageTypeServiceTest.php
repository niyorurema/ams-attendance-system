<?php

use App\Services\PointageTypeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PointageTypeServiceTest extends CIUnitTestCase
{
    public function testAssignsPositionTypesForDifferentDayLengths(): void
    {
        $service = new PointageTypeService();
        $horaires = $this->horairesObr();

        $unPointage = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
        ], $horaires);
        $this->assertSame(['CHECK_IN'], array_column($unPointage, 'type_evenement'));
        $this->assertSame(['VALIDE'], array_column($unPointage, 'statut_calcul'));

        $deuxPointages = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 17:35:00'],
        ], $horaires);
        $this->assertSame(['CHECK_IN', 'CHECK_OUT'], array_column($deuxPointages, 'type_evenement'));

        $troisPointages = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 12:05:00'],
            ['id' => 3, 'date_pointage' => '2026-10-03 17:35:00'],
        ], $horaires);
        $this->assertSame(['CHECK_IN', 'BREAK_OUT', 'CHECK_OUT'], array_column($troisPointages, 'type_evenement'));

        $quatrePointages = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 12:05:00'],
            ['id' => 3, 'date_pointage' => '2026-10-03 14:10:00'],
            ['id' => 4, 'date_pointage' => '2026-10-03 17:35:00'],
        ], $horaires);
        $this->assertSame(['CHECK_IN', 'BREAK_OUT', 'BREAK_IN', 'CHECK_OUT'], array_column($quatrePointages, 'type_evenement'));
        $this->assertSame(['VALIDE', 'VALIDE', 'VALIDE', 'VALIDE'], array_column($quatrePointages, 'statut_calcul'));
    }

    public function testLastPointageIsCheckOutForFiveOrMoreEvents(): void
    {
        $service = new PointageTypeService();
        $pointages = [];
        foreach (['07:25', '12:05', '13:30', '14:10', '15:00', '17:35'] as $index => $heure) {
            $pointages[] = [
                'id' => $index + 1,
                'date_pointage' => '2026-10-03 ' . $heure . ':00',
            ];
        }

        $resultat = $service->determinerTypesEtStatuts($pointages, $this->horairesObr());

        $this->assertSame(
            ['CHECK_IN', 'BREAK_OUT', 'BREAK_IN', 'BREAK_IN', 'BREAK_IN', 'CHECK_OUT'],
            array_column($resultat, 'type_evenement')
        );
    }

    public function testSortsPointagesBeforeAssigningPositionTypes(): void
    {
        $service = new PointageTypeService();
        $pointages = [
            ['id' => 2, 'date_pointage' => '2026-10-03 17:35:00'],
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
        ];

        $resultat = $service->determinerTypesEtStatuts($pointages, $this->horairesObr());

        $this->assertSame([1, 2], array_column($resultat, 'id'));
        $this->assertSame(['CHECK_IN', 'CHECK_OUT'], array_column($resultat, 'type_evenement'));
    }

    public function testCalculatesLateEarlyAndUnknownStatuses(): void
    {
        $service = new PointageTypeService();
        $horaires = $this->horairesObr();

        $retard = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:41:00'],
        ], $horaires);
        $this->assertSame('RETARD', $retard[0]['statut_calcul']);

        $anormal = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:19:00'],
        ], $horaires);
        $this->assertSame('ANORMAL', $anormal[0]['statut_calcul']);

        $sansPlagePause = $service->determinerTypesEtStatuts([
            ['id' => 1, 'date_pointage' => '2026-10-03 07:30:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 12:00:00'],
            ['id' => 3, 'date_pointage' => '2026-10-03 17:30:00'],
        ], [$horaires[0], $horaires[3]]);
        $this->assertSame('BREAK_OUT', $sansPlagePause[1]['type_evenement']);
        $this->assertSame('UNKNOWN', $sansPlagePause[1]['statut_calcul']);
    }

    public function testToleranceOverrideChangesOnlyCalculatedStatus(): void
    {
        $service = new PointageTypeService();
        $pointages = [
            ['id' => 1, 'date_pointage' => '2026-10-03 07:45:00'],
        ];

        $strict = $service->determinerTypesEtStatuts($pointages, $this->horairesObr(), 10);
        $wide = $service->determinerTypesEtStatuts($pointages, $this->horairesObr(), 30);

        $this->assertSame('CHECK_IN', $strict[0]['type_evenement']);
        $this->assertSame('RETARD', $strict[0]['statut_calcul']);
        $this->assertSame('CHECK_IN', $wide[0]['type_evenement']);
        $this->assertSame('VALIDE', $wide[0]['statut_calcul']);
    }

    public function testSimulationMatchesSinglePointagesToTheirNearestScheduleReference(): void
    {
        $service = new PointageTypeService();
        $horaires = $this->horairesObr();

        $midi = $service->simulerTypesEtStatutsParPlage([
            ['id' => 1, 'date_pointage' => '2026-10-03 12:05:00'],
        ], $horaires);
        $soir = $service->simulerTypesEtStatutsParPlage([
            ['id' => 2, 'date_pointage' => '2026-10-03 17:35:00'],
        ], $horaires);

        $this->assertSame('BREAK_OUT', $midi[0]['type_evenement']);
        $this->assertSame('VALIDE', $midi[0]['statut_calcul']);
        $this->assertSame('CHECK_OUT', $soir[0]['type_evenement']);
        $this->assertSame('VALIDE', $soir[0]['statut_calcul']);
    }

    public function testSimulationAlignsMultiplePointagesChronologically(): void
    {
        $service = new PointageTypeService();
        $horaires = $this->horairesObr();
        $pointages = [
            ['id' => 4, 'date_pointage' => '2026-10-03 17:35:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 12:05:00'],
            ['id' => 1, 'date_pointage' => '2026-10-03 07:25:00'],
            ['id' => 3, 'date_pointage' => '2026-10-03 14:10:00'],
        ];

        $resultat = $service->simulerTypesEtStatutsParPlage($pointages, $horaires);

        $this->assertSame(
            ['CHECK_IN', 'BREAK_OUT', 'BREAK_IN', 'CHECK_OUT'],
            array_column($resultat, 'type_evenement')
        );
        $this->assertSame(['VALIDE', 'VALIDE', 'VALIDE', 'VALIDE'], array_column($resultat, 'statut_calcul'));
    }

    public function testSimulationMarksExcessAndInvalidPointagesUnknown(): void
    {
        $service = new PointageTypeService();
        $horaires = $this->horairesObr();
        $pointages = [
            ['id' => 1, 'date_pointage' => '2026-10-03 07:30:00'],
            ['id' => 2, 'date_pointage' => '2026-10-03 12:00:00'],
            ['id' => 3, 'date_pointage' => 'not-a-date'],
            ['id' => 4, 'date_pointage' => '2026-10-03 14:00:00'],
            ['id' => 5, 'date_pointage' => '2026-10-03 17:30:00'],
            ['id' => 6, 'date_pointage' => '2026-10-03 18:00:00'],
        ];

        $resultat = $service->simulerTypesEtStatutsParPlage($pointages, $horaires);

        $parId = [];
        foreach ($resultat as $pointage) {
            $parId[$pointage['id']] = $pointage;
        }
        $this->assertSame('CHECK_IN', $parId[1]['type_evenement']);
        $this->assertSame('BREAK_OUT', $parId[2]['type_evenement']);
        $this->assertSame('BREAK_IN', $parId[4]['type_evenement']);
        $this->assertSame('CHECK_OUT', $parId[5]['type_evenement']);
        $this->assertSame('UNKNOWN', $parId[3]['type_evenement']);
        $this->assertSame('UNKNOWN', $parId[3]['statut_calcul']);
        $this->assertSame('UNKNOWN', $parId[6]['type_evenement']);
        $this->assertSame('UNKNOWN', $parId[6]['statut_calcul']);
    }

    public function testSimulationSupportsToleranceOverrideAndMissingReferences(): void
    {
        $service = new PointageTypeService();
        $pointage = [['id' => 1, 'date_pointage' => '2026-10-03 07:45:00']];

        $largeTolerance = $service->simulerTypesEtStatutsParPlage($pointage, $this->horairesObr(), 30);
        $sansReference = $service->simulerTypesEtStatutsParPlage($pointage, []);

        $this->assertSame('VALIDE', $largeTolerance[0]['statut_calcul']);
        $this->assertSame('UNKNOWN', $sansReference[0]['type_evenement']);
        $this->assertSame('UNKNOWN', $sansReference[0]['statut_calcul']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function horairesObr(): array
    {
        return [
            ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15', 'tolerance_min' => 10],
            ['type' => 'BREAK_OUT', 'debut' => '12:00', 'fin' => '12:30', 'tolerance_min' => 10],
            ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '14:00', 'tolerance_min' => 10],
            ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30', 'tolerance_min' => 10],
        ];
    }
}
