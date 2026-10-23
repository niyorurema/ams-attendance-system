<?php

namespace App\Commands;

use App\Services\RapportService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Envoie les rapports planifiés mensuels.
 */
class RapportsEnvoyerMensuels extends BaseCommand
{
    protected $group = 'Rapports';
    protected $name = 'rapports:envoyer-mensuels';
    protected $description = 'Envoie les rapports mensuels selon le planning de l’utilisateur.';
    protected $usage = 'php spark rapports:envoyer-mensuels';

    public function run(array $params): int
    {
        CLI::write('');
        CLI::write('Vérification des rapports mensuels...', 'yellow');

        try {
            $result = (new RapportService())->envoyerRapportsParType('MENSUEL');

            CLI::write(sprintf('Rapports traités : %d', $result['traite'] ?? 0));
            CLI::write(sprintf('Rapports envoyés : %d', $result['envoyes'] ?? 0));
            CLI::write(sprintf('Erreurs : %d', $result['erreurs'] ?? 0));

            return ($result['erreurs'] ?? 0) === 0 ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Échec de l’envoi des rapports mensuels : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
