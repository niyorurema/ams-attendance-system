<?php

namespace App\Commands;

use App\Services\ImportGpioService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Résume les raisons empêchant l'import des mandats de chefs GPIO.
 */
class GpioDiagnostiquerChefs extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:diagnostiquer-chefs';
    protected $description = 'Diagnostique les associations des chefs GPIO sans modifier AMS.';
    protected $usage = 'gpio:diagnostiquer-chefs [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS à vérifier (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $options = CLI::getOptions();
        $tenantValue = $options['tenant'] ?? null;
        if ($tenantValue === null) {
            foreach ($options as $option => $value) {
                if (str_starts_with($option, 'tenant=')) {
                    $tenantValue = substr($option, strlen('tenant='));
                    break;
                }
            }
        }
        $tenantId = $tenantValue === null
            ? 1
            : (is_string($tenantValue) ? filter_var($tenantValue, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        try {
            $stats = (new ImportGpioService())->importerChefs($tenantId, true);
            CLI::write('=== Diagnostic des chefs GPIO ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId . ' | mode lecture seule.');
            CLI::write('Mandats lus : ' . $stats['lues']);
            CLI::write('Mandats créables : ' . $stats['crees']);
            CLI::write('Mandats actualisables : ' . $stats['maj']);
            CLI::write('Mandats ignorés : ' . $stats['ignores']);
            foreach ($stats['raisons'] as $reason => $count) {
                CLI::write(sprintf('- %s : %d', $reason, $count), 'yellow');
            }
            CLI::write('Aucun nom, matricule, courriel ni identifiant individuel n’est affiché.');

            return $stats['erreurs'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec du diagnostic des chefs : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
