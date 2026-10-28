<?php

namespace App\Commands;

use App\Services\SystemJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/** Supprime les exécutions terminées au-delà de la rétention configurée. */
final class SystemJobsPruneHistory extends BaseCommand
{
    protected $group = 'System';
    protected $name = 'system:jobs:prune-history';
    protected $description = 'Purge l’historique terminé des tâches système après 90 jours.';
    protected $usage = 'php spark system:jobs:prune-history [retention_days]';
    protected $arguments = ['retention_days' => 'Durée de conservation entre 30 et 3650 jours (défaut : 90).'];

    public function run(array $params): int
    {
        $retentionDays = $params[0] ?? 90;
        if (filter_var($retentionDays, FILTER_VALIDATE_INT) === false
            || (int) $retentionDays < 30
            || (int) $retentionDays > 3650
        ) {
            CLI::error('La rétention doit être un entier compris entre 30 et 3650 jours.');
            return EXIT_ERROR;
        }

        try {
            $deleted = (new SystemJobService())->pruneHistory((int) $retentionDays);
            CLI::write(sprintf('Exécutions supprimées : %d (rétention : %d jours).', $deleted, $retentionDays));
            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            log_message('error', 'Purge de l’historique des tâches impossible : {error}', ['error' => $exception->getMessage()]);
            CLI::error('Impossible de purger l’historique des tâches.');
            return EXIT_ERROR;
        }
    }
}
