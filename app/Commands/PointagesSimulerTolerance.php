<?php

namespace App\Commands;

use App\Services\PointageTypeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Compare plusieurs tolérances sans écrire les résultats dans la base.
 */
class PointagesSimulerTolerance extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:simuler-tolerance';
    protected $description = 'Simule les statuts calculés selon plusieurs tolérances, en lecture seule.';
    protected $usage = 'pointages:simuler-tolerance [--tenant=1] [--tolerance=60]';
    protected $options = [
        '--tenant' => 'ID du tenant (défaut : 1).',
        '--tolerance' => 'Tolérance personnalisée à ajouter à la comparaison (minutes).',
    ];

    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }
        $toleranceOption = CLI::getOption('tolerance');
        $tolerancePersonnalisee = $toleranceOption === null ? null : filter_var($toleranceOption, FILTER_VALIDATE_INT);
        if ($toleranceOption !== null && ($tolerancePersonnalisee === false || $tolerancePersonnalisee < 0 || $tolerancePersonnalisee > 1440)) {
            CLI::error('La tolérance doit être un entier compris entre 0 et 1440 minutes.');

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
            $horaireDefaut = 0;
            foreach ($horaires as $horaire) {
                $id = (int) $horaire['id'];
                $plages = json_decode((string) $horaire['plages_horaires'], true);
                $plagesParHoraire[$id] = is_array($plages) ? $plages : [];
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

            $tolerances = [10, 30, 60, 120];
            if ($tolerancePersonnalisee !== null) {
                $tolerances[] = $tolerancePersonnalisee;
            }
            $tolerances = array_values(array_unique($tolerances));
            sort($tolerances);
            $compteurs = [];
            foreach ($tolerances as $tolerance) {
                $compteurs[$tolerance] = ['VALIDE' => 0, 'RETARD' => 0, 'ANORMAL' => 0, 'UNKNOWN' => 0];
            }

            $pointages = $db->table('pointages')
                ->select('id, employe_id, badge_id, date_pointage')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->orderBy('date_pointage', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            $service = new PointageTypeService();
            $groupes = [];
            foreach ($pointages as $pointage) {
                $employeId = (int) ($pointage['employe_id'] ?? 0);
                if ($employeId < 1) {
                    $employeId = $employeParBadge[(string) $pointage['badge_id']] ?? 0;
                }
                $jour = (new DateTimeImmutable((string) $pointage['date_pointage']))->format('Y-m-d');
                $key = $jour . '|' . ($employeId > 0 ? 'e:' . $employeId : 'b:' . (string) $pointage['badge_id']);
                $groupes[$key]['employe_id'] = $employeId;
                $groupes[$key]['jour'] = $jour;
                $groupes[$key]['pointages'][] = $pointage;
            }

            foreach ($groupes as $groupe) {
                $horaireId = $this->horairePourEmploye(
                    (int) $groupe['employe_id'],
                    (string) $groupe['jour'],
                    $affectationsParEmploye,
                    $plagesParHoraire,
                    $horaireDefaut
                );
                foreach ($tolerances as $tolerance) {
                    $classes = $service->determinerTypesEtStatuts(
                        $groupe['pointages'],
                        $plagesParHoraire[$horaireId],
                        $tolerance
                    );
                    foreach ($classes as $classe) {
                        $statut = (string) $classe['statut_calcul'];
                        $compteurs[$tolerance][$statut]++;
                    }
                }
            }

            $total = count($pointages);
            CLI::write('=== Simulation des tolérances ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write(sprintf('Horaire par défaut courant : #%d (%s)', $horaireDefaut, $this->nomHoraire($horaires, $horaireDefaut)));
            CLI::write('Pointages : ' . number_format($total, 0, ',', ' '));
            CLI::write('Mode : simulation en lecture seule; aucun pointage ni horaire n’est modifié.', 'yellow');
            CLI::write('Les horaires historiques ne sont pas versionnés; les affectations employé-datées et le défaut courant sont utilisés.', 'yellow');

            foreach ($compteurs as $tolerance => $comptes) {
                $pourcentages = [];
                foreach ($comptes as $statut => $nombre) {
                    $pourcentages[$statut] = $total > 0 ? round($nombre * 100 / $total, 2) : 0.0;
                }
                CLI::write(sprintf(
                    'Tolérance %d min → VALIDE %s (%.2f%%), RETARD %s (%.2f%%), ANORMAL %s (%.2f%%), UNKNOWN %s (%.2f%%)',
                    $tolerance,
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

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de la simulation de tolérance : ' . $e->getMessage());

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

    /**
     * @param array<int, array<string, mixed>> $horaires
     */
    private function nomHoraire(array $horaires, int $id): string
    {
        foreach ($horaires as $horaire) {
            if ((int) $horaire['id'] === $id) {
                return (string) $horaire['nom'];
            }
        }

        return 'inconnu';
    }
}
