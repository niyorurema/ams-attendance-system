<?php

namespace App\Commands;

use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les utilisateurs d’un capteur vers les employés.
 */
class CapteursSyncUsers extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:sync-users';
    protected $description = 'Synchronise les utilisateurs ZKTeco d’un capteur vers la base de données.';
    protected $usage = 'php spark capteurs:sync-users {capteur_id}';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur à synchroniser.',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? null;
        if ($capteurId === null || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark capteurs:sync-users {capteur_id}');

            return EXIT_ERROR;
        }

        try {
            $result = (new ZKTecoService())->syncEmployes((int) $capteurId);
            CLI::write(sprintf('Tenant #%d : %s', $result['tenant_id'], $result['status']));
            CLI::write(sprintf('Créés : %d | Mises à jour : %d | Ignorés : %d | Erreurs : %d',
                $result['stats']['crees'] ?? 0,
                $result['stats']['maj'] ?? 0,
                $result['stats']['ignores'] ?? 0,
                $result['stats']['erreurs'] ?? 0
            ));

            return $result['status'] === 'SUCCESS' ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Échec de la synchronisation des utilisateurs : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
