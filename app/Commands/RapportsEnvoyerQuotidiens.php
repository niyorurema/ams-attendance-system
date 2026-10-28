<?php

namespace App\Commands;

use App\Services\RapportService;
use App\Services\SystemJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Envoie les rapports planifiés quotidiens.
 */
class RapportsEnvoyerQuotidiens extends BaseCommand
{
    protected $group = 'Rapports';
    protected $name = 'rapports:envoyer-quotidiens';
    protected $description = 'Envoie les rapports de présence J-1 selon l’heure et les jours de travail de chaque tenant.';
    protected $usage = 'php spark rapports:envoyer-quotidiens';

    public function run(array $params): int
    {
        CLI::write('');
        CLI::write('Vérification des rapports quotidiens planifiés...', 'yellow');

        $jobs = null;
        $runId = null;
        $retryContext = [];
        try {
            $jobs = new SystemJobService();
            $runId = $jobs->start($this->name);
            $rapportService = new RapportService();
            $retryContext = $rapportService->contexteRapportsQuotidiens();
            $jobs->heartbeat($runId, ['retry_context' => $retryContext]);
            $result = $rapportService->envoyerRapportsQuotidiens($retryContext);

            CLI::write(sprintf('Rapports traités : %d', $result['traite'] ?? 0));
            CLI::write(sprintf('Rapports envoyés : %d', $result['envoyes'] ?? 0));
            CLI::write(sprintf('Rapports ignorés : %d', $result['ignores'] ?? 0));
            CLI::write(sprintf('Erreurs : %d', $result['erreurs'] ?? 0));
            $status = ($result['erreurs'] ?? 0) > 0 ? 'PARTIAL' : 'SUCCESS';
            $jobs->finish(
                $runId,
                $status,
                ['retry_context' => $retryContext, 'result' => $result],
                $status === 'PARTIAL' ? 'Un ou plusieurs rapports n’ont pas été envoyés.' : null
            );

            if (($result['erreurs'] ?? 0) > 0) {
                return EXIT_ERROR;
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            log_message('error', 'Échec de l’envoi des rapports quotidiens : {error}', ['error' => $e->getMessage()]);
            if ($jobs !== null && $runId !== null) {
                try {
                    $jobs->finish($runId, 'ERROR', ['retry_context' => $retryContext], $e->getMessage());
                } catch (Throwable $trackingError) {
                    log_message('critical', 'Impossible de journaliser l’échec des rapports : {error}', [
                        'error' => $trackingError->getMessage(),
                    ]);
                }
            }
            CLI::error('Échec de l’envoi des rapports quotidiens : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
