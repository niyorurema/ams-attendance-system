<?php

namespace App\Commands;

use App\Services\ZKTecoService;
use App\Services\SystemJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les pointages historiques d’un capteur.
 */
class CapteursSyncAttendance extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:sync-attendance';
    protected $description = 'Synchronise les pointages historiques d’un capteur ZKTeco vers la base.';
    protected $usage = 'php spark capteurs:sync-attendance {capteur_id} [--batch=500] [--limit=1000]';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur à synchroniser.',
    ];
    protected $options = [
        '--batch' => 'Nombre de pointages par lot.',
        '--limit' => 'Nombre maximal de pointages à traiter.',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? null;
        if ($capteurId === null || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark capteurs:sync-attendance {capteur_id}');

            return EXIT_ERROR;
        }

        $batch = CLI::getOption('batch');
        $batch = $batch === null ? 500 : filter_var($batch, FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 5000) {
            CLI::error('Option --batch invalide. La valeur doit être comprise entre 1 et 5000.');

            return EXIT_ERROR;
        }

        $limit = CLI::getOption('limit');
        $limit = $limit === null ? null : filter_var($limit, FILTER_VALIDATE_INT);
        if ($limit !== null && ($limit < 1 || $limit > 500000)) {
            CLI::error('Option --limit invalide. La valeur doit être un entier strictement positif.');

            return EXIT_ERROR;
        }

        $jobs = null;
        $runId = null;
        try {
            $jobs = new SystemJobService();
            $runId = $jobs->start($this->name, (int) $capteurId);
            $result = (new ZKTecoService())->syncPointages((int) $capteurId, $batch, $limit);
            $stats = $result['stats'];

            CLI::write(sprintf('Tenant #%d : %s', $result['tenant_id'], $result['status']));
            CLI::write(sprintf('Lus : %d | Insérés : %d | Doublons : %d | Invalides : %d',
                $stats['lus'] ?? 0,
                $stats['inseres'] ?? 0,
                $stats['doublons'] ?? 0,
                $stats['invalides'] ?? 0
            ));

            $success = $result['status'] === 'SUCCESS';
            $jobs->finish(
                $runId,
                $success ? 'SUCCESS' : 'ERROR',
                $stats,
                $success ? null : 'Le capteur a retourné un statut d’échec.'
            );

            return $success ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            log_message('error', 'Échec de la synchronisation des pointages du capteur {sensorId} : {error}', [
                'sensorId' => $capteurId,
                'error' => $e->getMessage(),
            ]);
            if ($jobs !== null && $runId !== null) {
                try {
                    $jobs->finish($runId, 'ERROR', [], $e->getMessage());
                } catch (Throwable $trackingError) {
                    log_message('critical', 'Impossible de journaliser l’échec du capteur {sensorId} : {error}', [
                        'sensorId' => $capteurId,
                        'error' => $trackingError->getMessage(),
                    ]);
                }
            }
            CLI::error('Échec de la synchronisation des pointages : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
