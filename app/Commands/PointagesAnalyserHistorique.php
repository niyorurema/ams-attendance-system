<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Produit un diagnostic en lecture seule de l'historique des pointages.
 */
class PointagesAnalyserHistorique extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:analyser-historique';
    protected $description = 'Analyse la distribution historique des pointages sans modifier la base.';
    protected $usage = 'pointages:analyser-historique [--tenant=1] [--json]';
    protected $options = [
        '--tenant' => 'ID du tenant (défaut : 1).',
        '--json' => 'Enregistre également le rapport JSON dans writable/logs.',
    ];

    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        try {
            $db = Database::connect();
            $tenant = $db->table('tenants')
                ->select('id')
                ->where('id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                CLI::error('Tenant introuvable.');

                return EXIT_ERROR;
            }

            $filters = 'tenant_id = ? AND deleted_at IS NULL';
            $stats = $db->query(
                "SELECT COUNT(*) AS total, MIN(date_pointage) AS debut, MAX(date_pointage) AS fin
                 FROM pointages WHERE {$filters}",
                [$tenantId]
            )->getRowArray();
            $parMois = $db->query(
                "SELECT DATE_FORMAT(date_pointage, '%Y-%m') AS mois, COUNT(*) AS total
                 FROM pointages WHERE {$filters}
                 GROUP BY mois ORDER BY mois",
                [$tenantId]
            )->getResultArray();
            $parHeure = $db->query(
                "SELECT DATE_FORMAT(date_pointage, '%H:%i') AS heure, COUNT(*) AS total
                 FROM pointages WHERE {$filters}
                 GROUP BY heure ORDER BY total DESC, heure ASC LIMIT 20",
                [$tenantId]
            )->getResultArray();
            $heuresParMois = $db->query(
                "SELECT DATE_FORMAT(date_pointage, '%Y-%m') AS mois,
                        DATE_FORMAT(date_pointage, '%H:%i') AS heure,
                        COUNT(*) AS total
                 FROM pointages WHERE {$filters}
                 GROUP BY mois, heure ORDER BY mois, total DESC, heure ASC",
                [$tenantId]
            )->getResultArray();
            $parJourSemaine = $db->query(
                "SELECT DAYOFWEEK(date_pointage) AS jour_semaine, COUNT(*) AS total
                 FROM pointages WHERE {$filters}
                 GROUP BY jour_semaine ORDER BY jour_semaine",
                [$tenantId]
            )->getResultArray();
            $quotidiens = $db->query(
                "SELECT pointages
                 FROM (
                     SELECT COUNT(*) AS pointages
                     FROM pointages WHERE {$filters}
                     GROUP BY DATE(date_pointage),
                              CASE WHEN employe_id IS NOT NULL
                                   THEN CONCAT('e:', employe_id)
                                   ELSE CONCAT('b:', badge_id)
                              END
                 ) AS par_employe_jour",
                [$tenantId]
            )->getResultArray();
            $parSource = $db->query(
                "SELECT source, COUNT(*) AS total FROM pointages WHERE {$filters} GROUP BY source ORDER BY source",
                [$tenantId]
            )->getResultArray();
            $parStatutTechnique = $db->query(
                "SELECT statut, COUNT(*) AS total FROM pointages WHERE {$filters} GROUP BY statut ORDER BY statut",
                [$tenantId]
            )->getResultArray();
            $deviations = $this->calculerEcartsObr($db, $tenantId);

            $total = (int) ($stats['total'] ?? 0);
            $effectifsJournaliers = array_map(static fn (array $ligne): int => (int) $ligne['pointages'], $quotidiens);
            sort($effectifsJournaliers);
            $moyenne = $effectifsJournaliers !== []
                ? $total / count($effectifsJournaliers)
                : 0.0;

            $heuresDominantesParMois = [];
            foreach ($heuresParMois as $ligne) {
                $mois = (string) $ligne['mois'];
                if (! isset($heuresDominantesParMois[$mois])) {
                    $heuresDominantesParMois[$mois] = [];
                }
                if (count($heuresDominantesParMois[$mois]) < 3) {
                    $heuresDominantesParMois[$mois][] = [
                        'heure' => $ligne['heure'],
                        'total' => (int) $ligne['total'],
                    ];
                }
            }

            $joursSemaine = [
                2 => 'Lundi',
                3 => 'Mardi',
                4 => 'Mercredi',
                5 => 'Jeudi',
                6 => 'Vendredi',
                7 => 'Samedi',
                1 => 'Dimanche',
            ];
            $weekdays = [];
            foreach ($joursSemaine as $numero => $nom) {
                $nombre = 0;
                foreach ($parJourSemaine as $ligne) {
                    if ((int) $ligne['jour_semaine'] === $numero) {
                        $nombre = (int) $ligne['total'];
                        break;
                    }
                }
                $weekdays[] = [
                    'jour' => $nom,
                    'total' => $nombre,
                    'pourcentage' => $total > 0 ? round($nombre * 100 / $total, 2) : 0.0,
                ];
            }

            $rapport = [
                'tenant_id' => $tenantId,
                'total' => $total,
                'periode' => ['debut' => $stats['debut'] ?? null, 'fin' => $stats['fin'] ?? null],
                'note_ecarts' => 'Les écarts utilisent les heures OBR de référence (07:30, 12:00, 14:00, 17:30) par position et ne reconstituent pas les horaires historiques.',
                'par_annee_mois' => array_map(
                    static fn (array $ligne): array => ['mois' => $ligne['mois'], 'total' => (int) $ligne['total']],
                    $parMois
                ),
                'top_20_heures' => array_map(
                    static fn (array $ligne): array => ['heure' => $ligne['heure'], 'total' => (int) $ligne['total']],
                    $parHeure
                ),
                'heures_dominantes_par_mois' => $heuresDominantesParMois,
                'par_source' => array_map(
                    static fn (array $ligne): array => ['source' => $ligne['source'], 'total' => (int) $ligne['total']],
                    $parSource
                ),
                'par_statut_technique' => array_map(
                    static fn (array $ligne): array => ['statut' => $ligne['statut'], 'total' => (int) $ligne['total']],
                    $parStatutTechnique
                ),
                'par_jour_semaine' => $weekdays,
                'pointages_par_employe_jour' => [
                    'moyenne' => round($moyenne, 2),
                    'mediane' => $this->mediane($effectifsJournaliers),
                    'minimum' => $effectifsJournaliers === [] ? 0 : min($effectifsJournaliers),
                    'maximum' => $effectifsJournaliers === [] ? 0 : max($effectifsJournaliers),
                    'jours_employe' => count($effectifsJournaliers),
                ],
                'ecart_moyen_obr_minutes' => $deviations,
            ];

            $this->afficherRapport($rapport);
            if (CLI::getOption('json') !== null) {
                $path = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR
                    . 'pointages_historique_tenant_' . $tenantId . '_' . date('Ymd_His') . '.json';
                $encoded = json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (file_put_contents($path, $encoded . PHP_EOL) === false) {
                    throw new RuntimeException('Impossible d’écrire le rapport JSON dans writable/logs.');
                }
                CLI::write('Rapport JSON : ' . $path, 'green');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de l’analyse historique : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * Calcule les écarts par position dans la journée par rapport aux plages OBR standard.
     *
     * @return array<string, array{reference:string,ecart_moyen_minutes:float,echantillons:int}>
     */
    private function calculerEcartsObr(BaseConnection $db, int $tenantId): array
    {
        $pointages = $db->table('pointages')
            ->select('id, COALESCE(employe_id, badge_id) AS employe, DATE(date_pointage) AS jour, date_pointage')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->orderBy('jour', 'ASC')
            ->orderBy('employe', 'ASC')
            ->orderBy('date_pointage', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $sums = [];
        $group = [];
        $groupKey = null;
        $traiterGroupe = static function (array $pointages) use (&$sums): void {
            $nombre = count($pointages);
            foreach ($pointages as $index => $pointage) {
                if ($nombre === 1 || $index === 0) {
                    $slot = 'CHECK_IN';
                    $reference = 7 * 60 + 30;
                } elseif ($index === $nombre - 1) {
                    $slot = 'CHECK_OUT';
                    $reference = 17 * 60 + 30;
                } elseif ($index === 1) {
                    $slot = 'BREAK_OUT';
                    $reference = 12 * 60;
                } else {
                    $slot = 'BREAK_IN';
                    $reference = 14 * 60;
                }

                $heure = strtotime((string) $pointage['date_pointage']);
                if ($heure === false) {
                    continue;
                }
                $minuteJour = ((int) date('G', $heure) * 60) + (int) date('i', $heure);
                $sums[$slot]['ecart'] = ($sums[$slot]['ecart'] ?? 0) + $minuteJour - $reference;
                $sums[$slot]['total'] = ($sums[$slot]['total'] ?? 0) + 1;
            }
        };

        foreach ($pointages as $pointage) {
            $key = $pointage['jour'] . '|' . $pointage['employe'];
            if ($groupKey !== null && $key !== $groupKey) {
                $traiterGroupe($group);
                $group = [];
            }
            $groupKey = $key;
            $group[] = $pointage;
        }
        if ($group !== []) {
            $traiterGroupe($group);
        }

        $references = [
            'CHECK_IN' => '07:30',
            'BREAK_OUT' => '12:00',
            'BREAK_IN' => '14:00',
            'CHECK_OUT' => '17:30',
        ];
        $resultat = [];
        foreach ($references as $type => $reference) {
            $total = (int) ($sums[$type]['total'] ?? 0);
            $resultat[$type] = [
                'reference' => $reference,
                'ecart_moyen_minutes' => $total > 0 ? round($sums[$type]['ecart'] / $total, 2) : 0.0,
                'echantillons' => $total,
            ];
        }

        return $resultat;
    }

    /**
     * @param list<int> $valeurs
     */
    private function mediane(array $valeurs): float
    {
        $nombre = count($valeurs);
        if ($nombre === 0) {
            return 0.0;
        }

        $milieu = intdiv($nombre, 2);
        if ($nombre % 2 === 1) {
            return (float) $valeurs[$milieu];
        }

        return ($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2;
    }

    /**
     * @param array<string, mixed> $rapport
     */
    private function afficherRapport(array $rapport): void
    {
        CLI::write('=== Analyse historique des pointages ===', 'cyan');
        CLI::write('Tenant : ' . $rapport['tenant_id']);
        CLI::write('Total : ' . number_format($rapport['total'], 0, ',', ' '));
        CLI::write(sprintf('Période : %s → %s', $rapport['periode']['debut'] ?? '—', $rapport['periode']['fin'] ?? '—'));

        CLI::write(PHP_EOL . '1. Répartition par année/mois', 'yellow');
        foreach ($rapport['par_annee_mois'] as $ligne) {
            CLI::write(sprintf('%s : %s pointages', $ligne['mois'], number_format($ligne['total'], 0, ',', ' ')));
        }

        CLI::write(PHP_EOL . '2. Répartition par heure (top 20)', 'yellow');
        foreach ($rapport['top_20_heures'] as $ligne) {
            CLI::write(sprintf('%s : %s', $ligne['heure'], number_format($ligne['total'], 0, ',', ' ')));
        }
        CLI::write(PHP_EOL . 'Pics horaires par mois (3 heures les plus fréquentes)', 'yellow');
        foreach ($rapport['heures_dominantes_par_mois'] as $mois => $heures) {
            $resume = array_map(
                static fn (array $ligne): string => $ligne['heure'] . ' (' . number_format($ligne['total'], 0, ',', ' ') . ')',
                $heures
            );
            CLI::write($mois . ' : ' . implode(', ', $resume));
        }

        CLI::write(PHP_EOL . '3. Répartition par jour de la semaine', 'yellow');
        foreach ($rapport['par_jour_semaine'] as $ligne) {
            CLI::write(sprintf('%-10s : %s (%.2f%%)', $ligne['jour'], number_format($ligne['total'], 0, ',', ' '), $ligne['pourcentage']));
        }

        CLI::write(PHP_EOL . '4. Écart moyen par rapport aux heures OBR', 'yellow');
        foreach ($rapport['ecart_moyen_obr_minutes'] as $type => $ligne) {
            CLI::write(sprintf(
                '%s %s : écart moyen %+0.2f min (%d pointages)',
                $type,
                $ligne['reference'],
                $ligne['ecart_moyen_minutes'],
                $ligne['echantillons']
            ));
        }

        $journalier = $rapport['pointages_par_employe_jour'];
        CLI::write(PHP_EOL . '5. Pointages par source / statut technique', 'yellow');
        CLI::write('Sources : ' . json_encode($rapport['par_source'], JSON_UNESCAPED_UNICODE));
        CLI::write('Statuts techniques : ' . json_encode($rapport['par_statut_technique'], JSON_UNESCAPED_UNICODE));

        CLI::write(PHP_EOL . '6. Nombre de pointages par employé par jour', 'yellow');
        CLI::write(sprintf(
            'Moyenne : %.2f; médiane : %.2f; min : %d; max : %d; journées-employé : %d',
            $journalier['moyenne'],
            $journalier['mediane'],
            $journalier['minimum'],
            $journalier['maximum'],
            $journalier['jours_employe']
        ));
    }
}
