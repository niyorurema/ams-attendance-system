<?php

use App\Services\CalculPresenceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CalculPresenceServiceOptInTest extends CIUnitTestCase
{
    public function testTenantSansOptInDelegueAuCalculHistorique(): void
    {
        $service = $this->creerService(actif: false);
        $this->assertSame(['legacy' => true], $service->interpreterJournee(5, '2026-10-12'));
    }

    public function testTenantOptInSansCreneauConfigureDelegueAuCalculHistorique(): void
    {
        $service = $this->creerService(actif: true, creneaux: []);

        $this->assertSame(['legacy' => true], $service->interpreterJournee(5, '2026-10-12'));
    }

    public function testJourFerieNonTravailleNeComptePasCommeAbsenceAvecNouvellesRegles(): void
    {
        $resultat = $this->creerService(
            creneaux: $this->quatreCreneaux(),
            jourFerie: ['nom' => 'Noël', 'travaille' => 0]
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('FERIE', $resultat['statut_global']);
        $this->assertSame('Noël', $resultat['jour_ferie_nom']);
        $this->assertSame('JOUR_FERIE', $resultat['details']['evaluation']);
    }

    public function testJourFerieTravailleSansPointageNeDevientPasUneAbsenceAvecNouvellesRegles(): void
    {
        $resultat = $this->creerService(
            creneaux: $this->quatreCreneaux(),
            jourFerie: ['nom' => 'Fête nationale', 'travaille' => 1]
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('FERIE', $resultat['statut_global']);
        $this->assertTrue($resultat['ferie_travail']);
        $this->assertFalse($resultat['prime_potentielle']);
    }

    public function testArriveeAvantLimiteEstPresenteEtArriveeTardiveEstEnRetard(): void
    {
        $service = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 07:25:00']]
        );
        $this->assertSame('PRESENT', $service->interpreterJournee(5, '2026-10-12')['statut_global']);

        $service = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 07:45:00']]
        );
        $this->assertSame('RETARD', $service->interpreterJournee(5, '2026-10-12')['statut_global']);
    }

    public function testAbsenceResteEnCoursAvantLaLimiteDeTolerance(): void
    {
        $resultat = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            maintenant: '2026-10-12 07:40:00'
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('EN_COURS', $resultat['statut_global']);
        $this->assertSame('AUCUNE_ARRIVEE_AVANT_ECHEANCE', $resultat['details']['evaluation']);
    }

    public function testAbsenceEstConfirmeeApresLaLimiteDeTolerance(): void
    {
        $resultat = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            maintenant: '2026-10-12 07:41:00'
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('ABSENT', $resultat['statut_global']);
    }

    public function testEvenementSansArriveeNeConfirmePasLabsenceAvantLeDelai(): void
    {
        $resultat = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-12 07:00:00']],
            maintenant: '2026-10-12 07:35:00'
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('EN_COURS', $resultat['statut_global']);
    }

    public function testEvenementSansArriveeConfirmeLabsenceApresLeDelai(): void
    {
        $resultat = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-12 07:00:00']],
            maintenant: '2026-10-12 07:41:00'
        )->interpreterJournee(5, '2026-10-12');

        $this->assertSame('ABSENT', $resultat['statut_global']);
        $this->assertSame('ABSENT', $resultat['statut_arrivee']);
    }

    public function testModeHoraireLibreNePorteAucunJugement(): void
    {
        $service = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            sansJugement: true
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('PRESENT', $resultat['statut_global']);
        $this->assertSame('AUCUN_JUGEMENT', $resultat['details']['evaluation']);
        $this->assertNull($resultat['statut_arrivee']);
    }

    public function testContratApresMidiUtiliseSonHeureDeDebut(): void
    {
        $contrat = [
            'mode_travail' => 'MI_TEMPS_APRES_MIDI',
            'heure_debut' => '13:00:00',
            'heure_fin' => '17:00:00',
            'jours_travail' => '[1,2,3,4,5]',
        ];
        $horaire = $this->horaire([
            ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '14:00'],
            ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30'],
        ]);
        $service = $this->creerService(
            creneaux: [$this->creneau('FIN_PAUSE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-12 13:00:00']],
            source: 'CONTRAT',
            horaire: $horaire,
            contrat: $contrat
        );

        $this->assertSame('PRESENT', $service->interpreterJournee(5, '2026-10-12')['statut_global']);
    }

    public function testContratApresMidiAvecArriveeTardiveEstEnRetard(): void
    {
        $contrat = [
            'mode_travail' => 'MI_TEMPS_APRES_MIDI',
            'heure_debut' => '13:00:00',
            'heure_fin' => '17:00:00',
            'jours_travail' => '[1,2,3,4,5]',
        ];
        $service = $this->creerService(
            creneaux: [$this->creneau('FIN_PAUSE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-12 13:20:00']],
            source: 'CONTRAT',
            horaire: $this->horaire([
                ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '14:00'],
                ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30'],
            ]),
            contrat: $contrat
        );

        $this->assertSame('RETARD', $service->interpreterJournee(5, '2026-10-12')['statut_global']);
    }

    public function testTempsPleinArriveA13hEstEnRetard(): void
    {
        $service = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10)],
            pointages: [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 13:00:00']]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('RETARD', $resultat['statut_global']);
        $this->assertSame('RETARD', $resultat['statut_arrivee']);
    }

    public function testAbsenceEstPrioritaireSurLesEtapesIncompletes(): void
    {
        $service = $this->creerService(creneaux: $this->quatreCreneaux());

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('ABSENT', $resultat['statut_global']);
        $this->assertSame('ABSENT', $resultat['statut_arrivee']);
        $this->assertSame('INCOMPLET', $resultat['statut_depart']);
    }

    public function testUnPointagePartielEstIncomplet(): void
    {
        $service = $this->creerService(
            creneaux: $this->quatreCreneaux(),
            pointages: [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 07:30:00']]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('INCOMPLET', $resultat['statut_global']);
        $this->assertSame('INCOMPLET', $resultat['statut_depart']);
    }

    public function testPointageHorsDeSaPlageEstSignale(): void
    {
        $service = $this->creerService(
            creneaux: [$this->creneau('ARRIVEE', 'PLAGE', null, 0, '07:30', '08:00')],
            pointages: [['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 22:00:00']]
        );

        $this->assertSame('HORS_CRENEAU', $service->interpreterJournee(5, '2026-10-12')['statut_global']);
    }

    public function testDepartAnticipeEstAgrege(): void
    {
        $service = $this->creerService(
            creneaux: [$this->creneau('DEPART', 'LIMITE_PRECOCE', '17:30', 10)],
            pointages: [['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-12 17:00:00']]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('ANTICIPE', $resultat['statut_global']);
        $this->assertSame('ANTICIPE', $resultat['statut_depart']);
    }

    public function testDepartApresHeureCibleProduitDesHeuresSupplementaires(): void
    {
        $service = $this->creerService(
            creneaux: [
                $this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10),
                $this->creneau('DEPART', 'LIMITE_PRECOCE', '17:30', 10),
            ],
            pointages: [
                ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 07:30:00'],
                ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-12 17:30:00'],
            ]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame(2.0, $resultat['heures_supplementaires']);
    }

    public function testPauseDansLesLimitesEstNormale(): void
    {
        $service = $this->creerService(
            creneaux: [
                $this->creneau('DEBUT_PAUSE', 'LIMITE_TARDIVE', '12:00', 10),
                $this->creneau('FIN_PAUSE', 'LIMITE_TARDIVE', '13:30', 10),
            ],
            pointages: [
                ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-12 12:00:00'],
                ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-12 13:30:00'],
            ]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('PRESENT', $resultat['statut_global']);
        $this->assertSame('OK', $resultat['statut_debut_pause']);
    }

    public function testPauseTropLongueEstAnormale(): void
    {
        $service = $this->creerService(
            creneaux: [
                $this->creneau('DEBUT_PAUSE', 'LIMITE_TARDIVE', '12:00', 10),
                $this->creneau('FIN_PAUSE', 'LIMITE_TARDIVE', '13:30', 45),
            ],
            pointages: [
                ['type_evenement' => 'BREAK_OUT', 'date_pointage' => '2026-10-12 12:00:00'],
                ['type_evenement' => 'BREAK_IN', 'date_pointage' => '2026-10-12 14:11:00'],
            ]
        );

        $resultat = $service->interpreterJournee(5, '2026-10-12');

        $this->assertSame('ANORMAL', $resultat['statut_global']);
        $this->assertSame('ANORMAL', $resultat['statut_debut_pause']);
    }

    public function testArrondiDesHeuresSupUtiliseLePasConfigurable(): void
    {
        $service = $this->creerService(
            creneaux: [
                $this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10),
                $this->creneau('DEPART', 'LIMITE_PRECOCE', '17:30', 10),
            ],
            pointages: [
                ['type_evenement' => 'CHECK_IN', 'date_pointage' => '2026-10-12 07:30:00'],
                ['type_evenement' => 'CHECK_OUT', 'date_pointage' => '2026-10-12 18:43:00'],
            ],
            parametres: ['pas_arrondi_heures_sup_minutes' => 30]
        );

        $this->assertSame(3.0, $service->interpreterJournee(5, '2026-10-12')['heures_supplementaires']);
    }

    public function testHierarchieSourceShiftContratHoraireIndividuelPuisTenant(): void
    {
        $contrat = [
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30:00',
            'heure_fin' => '17:30:00',
            'jours_travail' => '[1,2,3,4,5]',
        ];
        $horaire = $this->horaire([['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:00']]);
        $shift = ['nom' => 'Matin', 'heure_debut' => '06:00:00', 'heure_fin' => '14:00:00'];

        $this->assertSame('SHIFT', $this->creerServiceHierarchie($shift, $contrat, $horaire, $horaire)->source());
        $this->assertSame('CONTRAT', $this->creerServiceHierarchie(null, $contrat, $horaire, $horaire)->source());
        $this->assertSame('HORAIRE_INDIVIDUEL', $this->creerServiceHierarchie(null, null, $horaire, $horaire)->source());
        $this->assertSame('TENANT_DEFAUT', $this->creerServiceHierarchie(null, null, null, $horaire)->source());
    }

    private function creerServiceHierarchie(
        ?array $shift,
        ?array $contrat,
        ?array $horaireIndividuel,
        ?array $horaireDefaut
    ): object {
        return new class($shift, $contrat, $horaireIndividuel, $horaireDefaut) extends CalculPresenceService {
            public function __construct(
                private ?array $shift,
                private ?array $contrat,
                private ?array $horaireIndividuel,
                private ?array $horaireDefaut
            ) {
            }

            public function source(): string
            {
                return $this->chargerHoraireInterpretation(
                    ['employe_id' => 5, 'tenant_id' => 2],
                    '2026-10-12'
                )['source'];
            }

            protected function trouverShift(int $employeId, string $date): ?array
            {
                return $this->shift;
            }

            protected function chargerContratActif(int $tenantId, int $employeId, string $date): ?array
            {
                return $this->contrat;
            }

            protected function chargerHoraireIndividuel(int $tenantId, int $employeId, string $date): ?array
            {
                return $this->horaireIndividuel;
            }

            protected function chargerHoraireTenantDefaut(int $tenantId): array
            {
                return $this->horaireDefaut ?? [];
            }
        };
    }

    /** @return array<string, mixed> */
    private function creneau(
        string $type,
        string $mode,
        ?string $cible,
        int $tolerance,
        ?string $debut = null,
        ?string $fin = null
    ): array {
        return [
            'type_creneau' => $type,
            'mode' => $mode,
            'heure_cible' => $cible,
            'tolerance_minutes' => $tolerance,
            'plage_debut' => $debut,
            'plage_fin' => $fin,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function quatreCreneaux(): array
    {
        return [
            $this->creneau('ARRIVEE', 'LIMITE_TARDIVE', '07:30', 10),
            $this->creneau('DEBUT_PAUSE', 'LIMITE_TARDIVE', '12:00', 10),
            $this->creneau('FIN_PAUSE', 'LIMITE_TARDIVE', '13:30', 10),
            $this->creneau('DEPART', 'LIMITE_PRECOCE', '17:30', 10),
        ];
    }

    /** @param array<int, array<string, mixed>> $plages */
    private function horaire(array $plages): array
    {
        return ['nom' => 'Standard', 'heures_travail_normales' => 8, 'plages_horaires' => $plages];
    }

    /**
     * @param array<int, array<string, mixed>> $creneaux
     * @param array<int, array<string, mixed>> $pointages
     * @param array<string, mixed> $horaire
     * @param ?array<string, mixed> $contrat
     * @param array<string, mixed> $parametres
     */
    private function creerService(
        bool $actif = true,
        array $creneaux = [],
        array $pointages = [],
        string $source = 'TENANT_DEFAUT',
        ?array $horaire = null,
        ?array $contrat = null,
        bool $sansJugement = false,
        array $parametres = [],
        ?array $jourFerie = null,
        ?string $maintenant = null
    ): CalculPresenceService {
        return new class($actif, $creneaux, $pointages, $source, $horaire ?? $this->horaire([
            ['type' => 'CHECK_IN', 'debut' => '07:30', 'fin' => '08:00'],
            ['type' => 'BREAK_OUT', 'debut' => '12:00', 'fin' => '12:15'],
            ['type' => 'BREAK_IN', 'debut' => '13:30', 'fin' => '13:45'],
            ['type' => 'CHECK_OUT', 'debut' => '16:30', 'fin' => '17:30'],
        ]), $contrat, $sansJugement, $parametres, $jourFerie, $maintenant) extends CalculPresenceService {
            public function __construct(
                private bool $actif,
                private array $creneaux,
                private array $pointages,
                private string $source,
                private array $horaire,
                private ?array $contrat,
                private bool $sansJugement,
                private array $parametres,
                private ?array $jourFerie,
                private ?string $maintenant
            ) {
            }

            protected function maintenantTenant(int $tenantId): DateTimeImmutable
            {
                return new DateTimeImmutable($this->maintenant ?? '2026-10-12 12:00:00', new DateTimeZone('Africa/Bujumbura'));
            }

            protected function chargerContexteInterpretation(int $employeId): ?array
            {
                return [
                    'employe_id' => $employeId,
                    'tenant_id' => 2,
                    'actif_regles_nouvelles' => $this->actif ? 1 : 0,
                    'jours_travailles' => '1,2,3,4,5',
                ];
            }

            protected function trouverJourFerie(int $employeId, string $date): ?array
            {
                return $this->jourFerie;
            }

            protected function chargerCreneauxInterpretation(int $tenantId): array
            {
                return $this->creneaux;
            }

            protected function chargerHoraireInterpretation(array $contexte, string $date): array
            {
                return [
                    'source' => $this->source,
                    'horaire' => $this->horaire,
                    'contrat' => $this->contrat,
                    'sans_jugement' => $this->sansJugement,
                ];
            }

            protected function chargerPointagesInterpretation(
                int $tenantId,
                int $employeId,
                string $date,
                string $heureLimiteLendemain
            ): array {
                return $this->pointages;
            }

            protected function chargerParametresInterpretation(int $tenantId): array
            {
                return array_replace([
                    'ecart_max_association_minutes' => 360,
                    'seuil_heures_sup_minutes' => 0,
                    'mode_calcul_heures_sup' => 'ARRONDI',
                    'pas_arrondi_heures_sup_minutes' => 15,
                    'duree_pause_minimum_minutes' => 15,
                    'duree_pause_maximum_minutes' => 130,
                ], $this->parametres);
            }

            public function calculerJournee(int $employeId, string $date): array
            {
                return ['legacy' => true];
            }
        };
    }
}
