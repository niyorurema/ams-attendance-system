<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Associe par matricule les pointages qui n’ont pas encore d’employé.
 */
class PointagesAssocierParMatricule extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:associer-par-matricule';
    protected $description = 'Associe les pointages orphelins à leurs employés à partir de raw_data.user_id.';
    protected $usage = 'pointages:associer-par-matricule [--tenant=1] [--apply]';
    protected $options = [
        '--tenant' => 'ID du tenant à traiter (défaut : 1).',
        '--apply' => 'Applique les associations; sans cette option, le mode dry-run est utilisé.',
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

            $employes = $db->table('employes')
                ->select('id, matricule, user_id_capteur')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            $employesParMatricule = [];
            foreach ($employes as $employe) {
                $matricule = trim((string) ($employe['matricule'] ?? ''));
                if ($matricule === '') {
                    continue;
                }
                if (array_key_exists($matricule, $employesParMatricule)) {
                    $employesParMatricule[$matricule] = null;
                    continue;
                }
                $employesParMatricule[$matricule] = $employe;
            }

            $total = $db->table('pointages')
                ->where('tenant_id', $tenantId)
                ->where('employe_id', null)
                ->where('deleted_at', null)
                ->countAllResults();
            $apply = CLI::getOption('apply') !== null;
            CLI::write('=== Association des pointages par matricule ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write('Mode : ' . ($apply ? 'APPLICATION' : 'DRY-RUN (aucune modification)'), $apply ? 'red' : 'yellow');
            CLI::write('Pointages orphelins : ' . $total);

            $associes = 0;
            $sansMatricule = 0;
            $sansCorrespondance = 0;
            $dernierId = 0;
            $batchSize = 500;

            do {
                $pointages = $db->table('pointages')
                    ->select('id, badge_id, raw_data')
                    ->where('tenant_id', $tenantId)
                    ->where('employe_id', null)
                    ->where('deleted_at', null)
                    ->where('id >', $dernierId)
                    ->orderBy('id', 'ASC')
                    ->limit($batchSize)
                    ->get()
                    ->getResultArray();

                foreach ($pointages as $pointage) {
                    $dernierId = (int) $pointage['id'];
                    $rawData = json_decode((string) ($pointage['raw_data'] ?? ''), true);
                    if (! is_array($rawData)) {
                        $sansMatricule++;
                        continue;
                    }

                    $matricule = $rawData['user_id'] ?? null;
                    if (! is_string($matricule) && ! is_int($matricule)) {
                        $sansMatricule++;
                        continue;
                    }
                    $matricule = trim((string) $matricule);
                    if ($matricule === '') {
                        $sansMatricule++;
                        continue;
                    }

                    $employe = $employesParMatricule[$matricule] ?? null;
                    if ($employe === null) {
                        $sansCorrespondance++;
                        continue;
                    }

                    $rawData['badge_id'] = (int) $pointage['badge_id'];
                    $rawData['matricule'] = (string) $employe['matricule'];
                    $rawData['user_id_capteur'] = $employe['user_id_capteur'] ?? null;

                    if ($apply) {
                        $rawDataJson = json_encode(
                            $rawData,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        );
                        $updated = $db->table('pointages')
                            ->where('id', (int) $pointage['id'])
                            ->where('tenant_id', $tenantId)
                            ->where('employe_id', null)
                            ->update([
                                'employe_id' => (int) $employe['id'],
                                'raw_data' => $rawDataJson,
                            ]);
                        if (! $updated || $db->affectedRows() !== 1) {
                            throw new \RuntimeException('Échec de mise à jour du pointage #' . $pointage['id'] . '.');
                        }
                    }

                    $associes++;
                }
            } while (count($pointages) === $batchSize);

            CLI::write(($apply ? 'Associations appliquées' : 'Associations possibles') . ' : ' . $associes, 'green');
            CLI::write('Sans matricule exploitable : ' . $sansMatricule);
            CLI::write('Sans employé correspondant : ' . $sansCorrespondance);
            if (! $apply) {
                CLI::write('Simulation uniquement. Relancez avec --apply après vérification.', 'yellow');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de l’association des pointages : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
