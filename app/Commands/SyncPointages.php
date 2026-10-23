<?php

namespace App\Commands;

use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les pointages d’un capteur vers la base par lots.
 */
class SyncPointages extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteurs:sync-pointages';
    protected $description = 'Synchronise les pointages d’un capteur ZKTeco vers la base.';
    protected $usage = 'capteurs:sync-pointages {capteur_id} [--batch=500] [--limit=N]';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur enregistré dans la base.',
    ];
    protected $options = [
        '--batch' => 'Nombre de pointages lus et insérés par lot (1-5000, défaut : 500).',
        '--limit' => 'Nombre maximal de pointages à traiter (facultatif).',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? null;
        if ($capteurId === null || filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark capteurs:sync-pointages {capteur_id} [--batch=500] [--limit=N]');

            return EXIT_ERROR;
        }

        $batchValue = $this->getOptionValue('batch');
        $batchSize = $batchValue === null ? 500 : filter_var($batchValue, FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 5000) {
            CLI::error('Option --batch invalide. La valeur doit être comprise entre 1 et 5000.');

            return EXIT_ERROR;
        }

        $limitValue = $this->getOptionValue('limit');
        $limit = null;
        if ($limitValue !== null) {
            $limit = filter_var($limitValue, FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1) {
                CLI::error('Option --limit invalide. La valeur doit être un entier supérieur à zéro.');

                return EXIT_ERROR;
            }
        }

        $capteurId = (int) $capteurId;
        CLI::write('');
        CLI::write(sprintf('Synchronisation ADMS/Pull des pointages du capteur #%d...', $capteurId), 'yellow');
        CLI::write(sprintf('Taille de lot : %d%s', $batchSize, $limit === null ? '' : sprintf(' | Limite : %d', $limit)));

        try {
            $result = (new ZKTecoService())->syncPointages($capteurId, $batchSize, $limit);
            $stats = $result['stats'];

            CLI::write(sprintf('Résultat tenant #%d : %s', $result['tenant_id'], $result['status']));
            CLI::write(sprintf('Pointages lus : %d', $stats['lus']));
            CLI::write(sprintf('Insérés : %d', $stats['inseres']));
            CLI::write(sprintf('Doublons ignorés : %d', $stats['doublons']));
            CLI::write(sprintf('Enregistrements invalides : %d', $stats['invalides']));
            CLI::write(sprintf('Lots traités : %d', $stats['lots']));
            CLI::write(sprintf(
                'Checkpoint : %s%s',
                $stats['dernier_pointage_sync'] ?? 'aucun',
                $stats['checkpoint_avance'] ? ' (avancé)' : ' (inchangé)'
            ));

            return $result['status'] === 'SUCCESS' ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error(sprintf('Échec de la synchronisation du capteur #%d : %s', $capteurId, $e->getMessage()));

            return EXIT_ERROR;
        }
    }

    private function getOptionValue(string $name): ?string
    {
        $value = CLI::getOption($name);
        if (is_string($value)) {
            return $value;
        }

        foreach (CLI::getOptions() as $option => $optionValue) {
            if (preg_match('/^' . preg_quote($name, '/') . '=(.*)$/', $option, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}