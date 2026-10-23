<?php

namespace App\Commands;

use App\Services\AffectationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les affectations d’un capteur vers les dispositifs ZKTeco.
 */
class AffectationsSyncCapteur extends BaseCommand
{
    protected $group = 'Affectations';
    protected $name = 'affectations:sync-capteur';
    protected $description = 'Synchronise les affectations actives d’un capteur vers le matériel ZKTeco.';
    protected $usage = 'php spark affectations:sync-capteur {capteur_id}';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur à synchroniser.',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? null;
        if ($capteurId === null || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark affectations:sync-capteur {capteur_id}');

            return EXIT_ERROR;
        }

        try {
            $result = (new AffectationService())->syncAffectationsCapteur((int) $capteurId);
            CLI::write(sprintf('Capteur #%d : %s', $capteurId, $result['success'] ? 'OK' : 'ERREUR'));
            CLI::write(sprintf('Opérations : %d', count($result['operations'] ?? [])));

            return $result['success'] ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Échec de la synchronisation des affectations : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
