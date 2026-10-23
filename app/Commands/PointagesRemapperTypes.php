<?php

namespace App\Commands;

use App\Services\PointageReclassificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Recalcule les types et statuts de pointages, en simulation par défaut.
 */
class PointagesRemapperTypes extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:remapper-types';
    protected $description = 'Recalcule les types et statuts selon la position des pointages.';
    protected $usage = 'pointages:remapper-types [--tenant=1] [--apply]';
    protected $options = [
        '--tenant' => 'ID du tenant (défaut : 1).',
        '--apply' => 'Applique les changements; sans cette option le mode dry-run est utilisé.',
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

            $jours = $db->query(
                'SELECT DISTINCT DATE(date_pointage) AS jour
                 FROM pointages
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 ORDER BY jour ASC',
                [$tenantId]
            )->getResultArray();
            $apply = CLI::getOption('apply') !== null;
            CLI::write('=== Re-mapping types + statuts ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write('Jours à traiter : ' . count($jours));
            CLI::write('Mode : ' . ($apply ? 'APPLICATION' : 'DRY-RUN (aucune modification)'), $apply ? 'red' : 'yellow');

            $service = new PointageReclassificationService($db);
            $stats = [
                'traites' => 0,
                'modifies' => 0,
                'par_type' => [],
                'par_statut' => [],
                'par_horaire' => [],
                'par_mois' => [],
            ];
            foreach ($jours as $index => $row) {
                $jour = (string) $row['jour'];
                $jourStats = $service->reclassifierJournee($tenantId, $jour, $apply);
                $stats['traites'] += $jourStats['traites'];
                $stats['modifies'] += $jourStats['modifies'];
                foreach ($jourStats['par_type'] as $type => $nombre) {
                    $stats['par_type'][$type] = ($stats['par_type'][$type] ?? 0) + $nombre;
                }
                foreach ($jourStats['par_statut'] as $statut => $nombre) {
                    $stats['par_statut'][$statut] = ($stats['par_statut'][$statut] ?? 0) + $nombre;
                }
                foreach ($jourStats['par_horaire'] as $horaireId => $nombre) {
                    $stats['par_horaire'][$horaireId] = ($stats['par_horaire'][$horaireId] ?? 0) + $nombre;
                }
                $mois = substr($jour, 0, 7);
                $stats['par_mois'][$mois] ??= ['traites' => 0, 'par_type' => [], 'par_statut' => []];
                $stats['par_mois'][$mois]['traites'] += $jourStats['traites'];
                foreach ($jourStats['par_type'] as $type => $nombre) {
                    $stats['par_mois'][$mois]['par_type'][$type] = ($stats['par_mois'][$mois]['par_type'][$type] ?? 0) + $nombre;
                }
                foreach ($jourStats['par_statut'] as $statut => $nombre) {
                    $stats['par_mois'][$mois]['par_statut'][$statut] = ($stats['par_mois'][$mois]['par_statut'][$statut] ?? 0) + $nombre;
                }

                if (($index + 1) % 50 === 0 || $index + 1 === count($jours)) {
                    CLI::write(sprintf('Jours traités : %d / %d', $index + 1, count($jours)), 'yellow');
                }
            }

            CLI::write('Pointages traités : ' . $stats['traites']);
            CLI::write('Répartition par type : ' . json_encode($stats['par_type'], JSON_UNESCAPED_UNICODE));
            CLI::write('Répartition par statut : ' . json_encode($stats['par_statut'], JSON_UNESCAPED_UNICODE));
            CLI::write('Répartition par horaire ID : ' . json_encode($stats['par_horaire'], JSON_UNESCAPED_UNICODE));
            foreach ($stats['par_mois'] as $mois => $moisStats) {
                CLI::write(sprintf(
                    '%s : %d pointages; types=%s; statuts=%s',
                    $mois,
                    $moisStats['traites'],
                    json_encode($moisStats['par_type'], JSON_UNESCAPED_UNICODE),
                    json_encode($moisStats['par_statut'], JSON_UNESCAPED_UNICODE)
                ));
            }
            CLI::write(($apply ? 'Pointages modifiés' : 'Pointages à modifier') . ' : ' . $stats['modifies']);
            if (! $apply) {
                CLI::write('Simulation uniquement. Relancez avec --apply après vérification.', 'yellow');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec du re-mapping : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
