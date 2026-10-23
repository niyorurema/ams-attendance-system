<?php

use App\Services\CalculPresenceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CalculPresenceServiceTest extends CIUnitTestCase
{
    public function testCalculeUneJourneeNormaleAvecQuatrePointages(): void
    {
        $service = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-02 07:25:00'],
            ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-02 12:05:00'],
            ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-02 13:35:00'],
            ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-02 17:10:00'],
        ]);

        $resultat = $service->calculerJournee(1, '2026-10-02');

        $this->assertSame('NORMAL', $resultat['statut']);
        $this->assertSame(8.25, $resultat['heures_travaillees']);
        $this->assertSame([], $resultat['pointages_manquants']);
    }

    public function testSignaleUnRetardEtUnPointageManquant(): void
    {
        $service = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-02 07:45:00'],
            ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-02 12:05:00'],
            ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-02 17:10:00'],
        ]);

        $resultat = $service->calculerJournee(1, '2026-10-02');

        $this->assertSame('ANORMAL', $resultat['statut']);
        $this->assertSame(15, $resultat['retard_total_minutes']);
        $this->assertSame(['BREAK_IN à 13:30'], $resultat['pointages_manquants']);
    }

    public function testAppliqueLaToleranceConfigureeEtLesHeuresDeReferenceDesPlages(): void
    {
        $service = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-02 07:45:00'],
            ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-02 12:05:00'],
            ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-02 13:50:00'],
            ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-02 17:10:00'],
        ], null, [1, 2, 3, 4, 5], [
            ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15', 'tolerance_min' => 20],
            ['type' => 'BREAK_OUT', 'debut' => '12:00', 'fin' => '12:30', 'tolerance_min' => 20],
            ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '14:00', 'tolerance_min' => 20],
            ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30', 'tolerance_min' => 20],
        ]);

        $resultat = $service->calculerJournee(1, '2026-10-02');

        $this->assertSame('NORMAL', $resultat['statut']);
        $this->assertSame(0, $resultat['retard_total_minutes']);
        $this->assertSame(['07:30', '12:00', '14:00', '17:30'], array_column($resultat['analyse_plages'], 'attendu'));
    }

    public function testUneJourneeCouranteInacheveeNestPasClasseeAnormalePourSesEtapesFutures(): void
    {
        $service = $this->creerService([]);
        $horaire = [
            'nom' => 'Standard',
            'seuil_heures_anormal' => 6,
            'plages_horaires' => [
                ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:15', 'tolerance_min' => 10],
                ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30', 'tolerance_min' => 10],
            ],
        ];

        $resultat = $service->calculerDirect(
            $horaire,
            [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-14 07:30:00']],
            '2026-10-14',
            new DateTimeImmutable('2026-10-14 12:00:00', new DateTimeZone('Africa/Bujumbura'))
        );

        $this->assertSame('EN_COURS', $resultat['statut']);
        $this->assertSame('A_VENIR', $resultat['analyse_plages'][1]['statut']);
        $this->assertSame([], $resultat['pointages_manquants']);
    }

    public function testSignaleUnEmployeAbsent(): void
    {
        $resultat = $this->creerService([])->calculerJournee(1, '2026-10-02');

        $this->assertSame('ABSENT', $resultat['statut']);
        $this->assertSame(4, count($resultat['pointages_manquants']));
        $this->assertCount(4, $resultat['analyse_plages']);
        $this->assertSame(['MANQUANT', 'MANQUANT', 'MANQUANT', 'MANQUANT'], array_column($resultat['analyse_plages'], 'statut'));
    }

    public function testRefuseUneGranulariteEtUnePeriodeInvalideAvantTouteRequete(): void
    {
        $service = new class() extends CalculPresenceService {
            public function __construct()
            {
            }
        };

        try {
            $service->getStatsPresenceParJour(1, '2026-10-01', '2026-10-02', 'annee');
            $this->fail('Une granularité invalide doit être refusée.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('La granularité doit être jour, semaine ou mois.', $exception->getMessage());
        }

        try {
            $service->getStatsPresenceParJour(1, '2025-01-01', '2026-01-02');
            $this->fail('Une plage supérieure à 366 jours doit être refusée.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('La période ne peut pas dépasser 366 jours.', $exception->getMessage());
        }
    }

    public function testCalculeUnShiftDeNuitAvecPointageDeSortieLeLendemain(): void
    {
        $service = $this->creerService([]);
        $resultat = $service->calculerShift(
            ['nom' => 'Nuit', 'heure_debut' => '22:00:00', 'heure_fin' => '06:00:00'],
            '2026-10-02',
            [
                ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-02 22:00:00'],
                ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-03 06:00:00'],
            ]
        );

        $this->assertSame('NORMAL', $resultat['statut']);
        $this->assertSame(8.0, $resultat['heures_travaillees']);
        $this->assertSame([], $resultat['pointages_manquants']);
    }

    public function testClasseUnRetardSurUnShiftSansLeConfondreAvecUneAnomalieDeDuree(): void
    {
        $service = $this->creerService([]);
        $resultat = $service->calculerShift(
            ['nom' => 'Matin', 'heure_debut' => '06:00:00', 'heure_fin' => '14:00:00'],
            '2026-10-02',
            [
                ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-02 06:20:00'],
                ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-02 14:00:00'],
            ]
        );

        $this->assertSame('RETARD', $resultat['statut']);
        $this->assertSame(20, $resultat['retard_total_minutes']);
        $this->assertSame(7.67, $resultat['heures_travaillees']);
    }

    public function testJourFerieNonTravailleNeComptePasCommeAbsenceMemeAvecDesPointages(): void
    {
        $service = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-12-25 07:30:00'],
        ], ['nom' => 'Noël', 'travaille' => 0]);

        $resultat = $service->calculerJournee(1, '2026-12-25');

        $this->assertSame('FERIE', $resultat['statut']);
        $this->assertSame('Noël', $resultat['jour_ferie_nom']);
        $this->assertSame([], $resultat['pointages_manquants']);
    }

    public function testJourFerieTravailleSansPointageNEstPasUneAbsence(): void
    {
        $resultat = $this->creerService([], ['nom' => 'Fête nationale', 'travaille' => 1])
            ->calculerJournee(1, '2026-07-01');

        $this->assertSame('FERIE', $resultat['statut']);
        $this->assertTrue($resultat['ferie_travail']);
        $this->assertFalse($resultat['prime_potentielle']);
    }

    public function testJourFerieTravailleAvecPresenceSignaleLaPrimePotentielle(): void
    {
        $resultat = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-07-01 07:30:00'],
            ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-07-01 12:00:00'],
            ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-07-01 13:30:00'],
            ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-07-01 16:30:00'],
        ], ['nom' => 'Fête nationale', 'travaille' => 1])
            ->calculerJournee(1, '2026-07-01');

        $this->assertSame('NORMAL', $resultat['statut']);
        $this->assertTrue($resultat['jour_ferie']);
        $this->assertTrue($resultat['prime_potentielle']);
    }

    public function testJourDeReposNeComptePasCommeAbsence(): void
    {
        $resultat = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-03 07:30:00'],
        ])->calculerJournee(1, '2026-10-03');

        $this->assertSame('REPOS', $resultat['statut']);
        $this->assertSame([], $resultat['pointages_manquants']);
        $this->assertSame(0.0, $resultat['heures_travaillees']);
    }

    public function testJourDeWeekendConfigureCommeTravailleEstCalculeNormalement(): void
    {
        $resultat = $this->creerService([
            ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-03 07:30:00'],
            ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-03 12:00:00'],
            ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-03 13:30:00'],
            ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-03 16:30:00'],
        ], null, [1, 2, 3, 4, 5, 6])->calculerJournee(1, '2026-10-03');

        $this->assertSame('NORMAL', $resultat['statut']);
    }

    private function creerService(
        array $pointages,
        ?array $jourFerie = null,
        array $joursTravailles = [1, 2, 3, 4, 5],
        ?array $plagesHoraire = null
    ): CalculPresenceService
    {
        return new class($pointages, $jourFerie, $joursTravailles, $plagesHoraire) extends CalculPresenceService {
            public function __construct(
                private array $pointages,
                private ?array $jourFerie,
                private array $joursTravailles,
                private ?array $plagesHoraire
            )
            {
            }

            protected function trouverHoraire(int $employeId, string $date): array
            {
                return [
                    'nom' => 'Standard OBR',
                    'seuil_heures_anormal' => 6,
                    'plages_horaires' => $this->plagesHoraire ?? [
                        ['type' => 'CHECK_IN', 'debut' => '07:30'],
                        ['type' => 'BREAK_OUT', 'debut' => '12:00'],
                        ['type' => 'BREAK_IN', 'debut' => '13:30'],
                        ['type' => 'CHECK_OUT', 'debut' => '16:30'],
                    ],
                ];
            }

            protected function trouverShift(int $employeId, string $date): ?array
            {
                return null;
            }

            protected function trouverJourFerie(int $employeId, string $date): ?array
            {
                return $this->jourFerie;
            }

            protected function estJourSemaineTravaille(int $employeId, string $date): bool
            {
                return in_array((int) (new DateTimeImmutable($date))->format('N'), $this->joursTravailles, true);
            }

            protected function trouverPointagesDuJour(int $employeId, string $date, bool $inclureLendemain = false): array
            {
                return $this->pointages;
            }

            public function calculerShift(array $shift, string $date, array $pointages): array
            {
                return $this->calculerDepuisDonnees($this->horaireDepuisShift($shift, $date), $pointages);
            }

            public function calculerDirect(array $horaire, array $pointages, string $date, DateTimeImmutable $maintenant): array
            {
                return $this->calculerDepuisDonnees($horaire, $pointages, $date, $maintenant);
            }
        };
    }
}
