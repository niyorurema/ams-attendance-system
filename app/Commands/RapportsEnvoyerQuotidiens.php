<?php

namespace App\Commands;

use App\Services\RapportService;
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

        try {
            $result = (new RapportService())->envoyerRapportsQuotidiens();

            CLI::write(sprintf('Rapports traités : %d', $result['traite'] ?? 0));
            CLI::write(sprintf('Rapports envoyés : %d', $result['envoyes'] ?? 0));
            CLI::write(sprintf('Rapports ignorés : %d', $result['ignores'] ?? 0));
            CLI::write(sprintf('Erreurs : %d', $result['erreurs'] ?? 0));

            if (($result['erreurs'] ?? 0) > 0) {
                return EXIT_ERROR;
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de l’envoi des rapports quotidiens : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
