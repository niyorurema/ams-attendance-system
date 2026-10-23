<?php

namespace App\Commands;

use App\Services\PointageTypeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Compare le classement historique par position à une simulation par plages.
 */
class PointagesSimulerPlagesHoraires extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:simuler-plages-horaires';
    protected $description = 'Compare un classement par heure de référence, sans modifier les pointages.';
    protected $usage = 'pointages:simuler-plages-horaires [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant à analyser (défaut : 1).',
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

            $horaires = $db->table('tenant_horaires')
                ->select('id, nom, plages_horaires, est_defaut')
                ->where('tenant_id', $tenantId)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            $plagesParHoraire = [];
            $nomsHoraires = [];
            $horaireDefaut = 0;
            foreach ($horaires as $horaire) {
                $id = (int) $horaire['id'];
                $plages = json_decode((string) $horaire['plages_horaires'], true);
                $plagesParHoraire[$id] = is_array($plages) ? $plages : [];
                $nomsHoraires[$id] = (string) $horaire['nom'];
                if ((int) $horaire['est_defaut'] === 1) {
                    $horaireDefaut = $id;
                }
            }
            if ($horaireDefaut < 1) {
                CLI::error('Aucun horaire actif par défaut pour ce tenant.');
                return EXIT_ERROR;
            }

            $employes = $db->table('employes')
                ->select('id, badge_id')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            $employeParBadge = [];
            foreach ($employes as $employe) {
                if ($employe['badge_id'] !== null) {
                    $employeParBadge[(string) $employe['badge_id']] = (int) $employe['id'];
                }
            }

            $affectations = $db->table('employe_horaire')
                ->select('employe_id, horaire_id, date_debut, date_fin')
                ->where('tenant_id', $tenantId)
                ->orderBy('date_debut', 'DESC')
                ->get()
                ->getResultArray();
            $affectationsParEmploye = [];
            foreach ($affectations as $affectation) {
                $affectationsParEmploye[(int) $affectation['employe_id']][] = $affectation;
            }

            $pointages = $db->table('pointages')
                ->select('id, employe_id, badge_id, date_pointage')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->orderBy('date_pointage', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
            $groupes = [];
            foreach ($pointages as $pointage) {
                $employeId = (int) ($pointage['employe_id'] ?? 0);
                if ($employeId < 1) {
                    $employeId = $employeParBadge[(string) $pointage['badge_id']] ?? 0;
                }
                $jour = (new DateTimeImmutable((string) $pointage['date_pointage']))->format('Y-m-d');
                $cle = $jour . '|' . ($employeId > 0 ? 'e:' . $employeId : 'b:' . (string) $pointage['badge_id']);
                $groupes[$cle]['employe_id'] = $employeId;
                $groupes[$cle]['jour'] = $jour;
                $groupes[$cle]['pointages'][] = $pointage;
            }

            $tolerances = [10, 30, 60, 120];
            $compteurs = [];
            foreach ($tolerances as $tolerance) {
                $compteurs[$tolerance] = [
                    'position' => ['VALIDE' => 0, 'RETARD' => 0, 'ANORMAL' => 0, 'UNKNOWN' => 0],
                    'plage' => ['VALIDE' => 0, 'RETARD' => 0, 'ANORMAL' => 0, 'UNKNOWN' => 0],
                ];
            }

            $service = new PointageTypeService();
            foreach ($groupes as $groupe) {
                $horaireId = $this->horairePourEmploye(
                    (int) $groupe['employe_id'],
                    (string) $groupe['jour'],
                    $affectationsParEmploye,
                    $plagesParHoraire,
                    $horaireDefaut
                );
                $plages = $plagesParHoraire[$horaireId] ?? [];
                foreach ($tolerances as $tolerance) {
                    foreach ([
                        'position' => $service->determinerTypesEtStatuts($groupe['pointages'], $plages, $tolerance),
                        'plage' => $service->simulerTypesEtStatutsParPlage($groupe['pointages'], $plages, $tolerance),
                    ] as $strategie => $classes) {
                        foreach ($classes as $classe) {
                            $statut = (string) ($classe['statut_calcul'] ?? 'UNKNOWN');
                            if (! array_key_exists($statut, $compteurs[$tolerance][$strategie])) {
                                $statut = 'UNKNOWN';
                            }
                            $compteurs[$tolerance][$strategie][$statut]++;
                        }
                    }
                }
            }

            $total = count($pointages);
            CLI::write('=== Comparaison des stratégies de classement ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write(sprintf('Horaire par défaut : #%d (%s)', $horaireDefaut, $nomsHoraires[$horaireDefaut] ?? 'inconnu'));
            CLI::write('Pointages : ' . number_format($total, 0, ',', ' '));
            CLI::write(sprintf('Journées employé/badge simulées : %s', number_format(count($groupes), 0, ',', ' ')));
            CLI::write('Lecture seule : aucun pointage ni horaire n’est modifié.', 'yellow');
            CLI::write('Position = première/dernière lecture; plages = alignement chronologique à coût horaire minimal, chaque plage utilisée au plus une fois.');
            CLI::write('Les pointages excédant le nombre de plages deviennent UNKNOWN dans la simulation par plages.');

            foreach ($compteurs as $tolerance => $strategies) {
                CLI::write(PHP_EOL . sprintf('Tolérance %d min :', $tolerance), 'cyan');
                foreach ($strategies as $strategie => $comptes) {
                    $pourcentages = [];
                    foreach ($comptes as $statut => $nombre) {
                        $pourcentages[$statut] = $total > 0 ? $nombre * 100 / $total : 0;
                    }
                    CLI::write(sprintf(
                        '- %s → VALIDE %s (%.2f%%), RETARD %s (%.2f%%), ANORMAL %s (%.2f%%), UNKNOWN %s (%.2f%%)',
                        $strategie === 'position' ? 'Par position' : 'Par plages',
                        number_format($comptes['VALIDE'], 0, ',', ' '),
                        $pourcentages['VALIDE'],
                        number_format($comptes['RETARD'], 0, ',', ' '),
                        $pourcentages['RETARD'],
                        number_format($comptes['ANORMAL'], 0, ',', ' '),
                        $pourcentages['ANORMAL'],
                        number_format($comptes['UNKNOWN'], 0, ',', ' '),
                        $pourcentages['UNKNOWN']
                    ));
                }
            }

            CLI::write(PHP_EOL . 'Simulation exploratoire uniquement : valider les règles métier avant toute reclassification.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Échec de la simulation par plages horaires : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $affectationsParEmploye
     * @param array<int, array<int, array<string, mixed>>> $plagesParHoraire
     */
    private function horairePourEmploye(
        int $employeId,
        string $jour,
        array $affectationsParEmploye,
        array $plagesParHoraire,
        int $horaireDefaut
    ): int {
        foreach ($affectationsParEmploye[$employeId] ?? [] as $affectation) {
            if ($affectation['date_debut'] <= $jour
                && ($affectation['date_fin'] === null || $affectation['date_fin'] >= $jour)
                && isset($plagesParHoraire[(int) $affectation['horaire_id']])
            ) {
                return (int) $affectation['horaire_id'];
            }
        }

        return $horaireDefaut;
    }
}
