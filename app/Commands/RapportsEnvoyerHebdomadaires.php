<?php

namespace App\Commands;

use App\Services\RapportService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Envoie les rapports planifiés hebdomadaires.
 */
class RapportsEnvoyerHebdomadaires extends BaseCommand
{
    protected $group = 'Rapports';
    protected $name = 'rapports:envoyer-hebdomadaires';
    protected $description = 'Envoie les rapports hebdomadaires selon le planning de l’utilisateur.';
    protected $usage = 'php spark rapports:envoyer-hebdomadaires';

    public function run(array $params): int
    {
        CLI::write('');
        CLI::write('Vérification des rapports hebdomadaires...', 'yellow');

        try {
            $result = (new RapportService())->envoyerRapportsParType('HEBDOMADAIRE');

            CLI::write(sprintf('Rapports traités : %d', $result['traite'] ?? 0));
            CLI::write(sprintf('Rapports envoyés : %d', $result['envoyes'] ?? 0));
            CLI::write(sprintf('Erreurs : %d', $result['erreurs'] ?? 0));

            return ($result['erreurs'] ?? 0) === 0 ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Échec de l’envoi des rapports hebdomadaires : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
