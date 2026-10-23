<?php

namespace App\Commands;

use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les utilisateurs d’un capteur vers la table des employés.
 */
class SyncEmployes extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:sync-employes';
    protected $description = 'Synchronise les employés d’un capteur ZKTeco vers la base.';
    protected $usage = 'capteurs:sync-employes {capteur_id}';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur enregistré dans la base.',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? null;

        if ($capteurId === null || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark capteurs:sync-employes {capteur_id}');

            return EXIT_ERROR;
        }

        $capteurId = (int) $capteurId;

        CLI::write('');
        CLI::write(sprintf('Synchronisation des employés du capteur #%d...', $capteurId), 'yellow');

        try {
            $result = (new ZKTecoService())->syncEmployes($capteurId);
            $stats = $result['stats'];
            CLI::write(sprintf('Résultat pour le tenant #%d : %s', $result['tenant_id'], $result['status']));
            CLI::write(sprintf('Créés : %d', $stats['crees']));
            CLI::write(sprintf('Mis à jour : %d', $stats['maj']));
            CLI::write(sprintf('Ignorés : %d', $stats['ignores']));
            CLI::write(sprintf('Erreurs : %d', $stats['erreurs']));

            if ($result['erreurs_details'] !== []) {
                CLI::write('Détail des premières erreurs :', 'red');
                foreach (array_slice($result['erreurs_details'], 0, 5) as $error) {
                    CLI::write(sprintf(
                        '  UID %s (userid %s) : %s',
                        $error['uid'],
                        $error['userid'],
                        $error['erreur']
                    ), 'red');
                }
            }

            return $result['status'] === 'SUCCESS' ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error(sprintf('Échec de la synchronisation du capteur #%d : %s', $capteurId, $e->getMessage()));

            return EXIT_ERROR;
        }
    }
}