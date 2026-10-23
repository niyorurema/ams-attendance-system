<?php

namespace App\Commands;

use App\Services\ImportGpioService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Importe la hiérarchie, les chefs et les affectations employés depuis GPIO.
 */
class GpioImporter extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:importer';
    protected $description = 'Importe les référentiels GPIO dans AMS, en dry-run par défaut.';
    protected $usage = 'gpio:importer [--tenant=1] [--step=hierarchie|chefs|employes|all] [--dry-run|--apply]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS cible (défaut : 1).',
        '--step' => 'Étape : hierarchie, chefs, employes ou all (défaut : all).',
        '--dry-run' => 'Simule sans écrire dans AMS (mode par défaut).',
        '--apply' => 'Applique les changements dans AMS.',
        '--json' => 'Affiche le résumé structuré en JSON.',
    ];

    public function run(array $params): int
    {
        $tenantOption = $this->optionValue('tenant');
        $tenantId = $tenantOption === null ? 1 : (is_string($tenantOption) ? filter_var($tenantOption, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        $apply = CLI::getOption('apply') !== null;
        $dryRunOption = CLI::getOption('dry-run') !== null;
        if ($apply && $dryRunOption) {
            CLI::error('Choisissez --dry-run ou --apply, pas les deux.');

            return EXIT_ERROR;
        }

        $stepOption = $this->optionValue('step');
        $step = is_string($stepOption) ? strtolower(trim($stepOption)) : ($stepOption === null ? 'all' : '');
        $methodes = [
            'hierarchie' => 'importerHierarchie',
            'chefs' => 'importerChefs',
            'employes' => 'assignerEmployesAuxUnites',
        ];
        if ($step !== 'all' && ! isset($methodes[$step])) {
            CLI::error('Étape invalide. Valeurs autorisées : hierarchie, chefs, employes, all.');

            return EXIT_ERROR;
        }

        try {
            $amsDb = Database::connect();
            $service = new ImportGpioService(null, $amsDb);
            $etapes = $step === 'all' ? array_keys($methodes) : [$step];
            $resultats = [];
            $transactionStarted = false;
            if ($apply) {
                $amsDb->transBegin();
                $transactionStarted = true;
            }

            foreach ($etapes as $etape) {
                $resultats[$etape] = $service->{$methodes[$etape]}($tenantId, ! $apply);
            }

            $erreurs = array_sum(array_column($resultats, 'erreurs'));
            if ($apply) {
                if ($erreurs > 0 || $amsDb->transStatus() === false) {
                    throw new \RuntimeException(sprintf(
                        'Import annulé : %d erreur(s) détectée(s), transaction AMS annulée.',
                        $erreurs
                    ));
                }
                $amsDb->transCommit();
                $transactionStarted = false;
            }

            $rapport = [
                'tenant_id' => $tenantId,
                'mode' => $apply ? 'apply' : 'dry-run',
                'etapes' => $resultats,
            ];
            if (CLI::getOption('json') !== null) {
                CLI::write(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                CLI::write('=== Import GPIO vers AMS ===', 'cyan');
                CLI::write('Tenant : ' . $tenantId);
                CLI::write('Mode : ' . ($apply ? 'APPLICATION AMS' : 'DRY-RUN (aucune écriture)'), $apply ? 'red' : 'yellow');
                CLI::write('Base source GPIO : lecture seule.');
                foreach ($resultats as $nomEtape => $stats) {
                    CLI::write(PHP_EOL . strtoupper($nomEtape), 'yellow');
                    CLI::write(sprintf(
                        'Lues : %d | Créées : %d | Mises à jour : %d | Ignorées : %d | Erreurs : %d',
                        $stats['lues'],
                        $stats['crees'],
                        $stats['maj'],
                        $stats['ignores'],
                        $stats['erreurs']
                    ));
                    foreach ($stats['raisons'] ?? [] as $raison => $nombre) {
                        CLI::write(sprintf('- Motif %s : %d', $raison, $nombre), 'yellow');
                    }
                    foreach (array_slice($stats['details'], 0, 10) as $detail) {
                        CLI::write('- ' . $detail, $stats['erreurs'] > 0 ? 'yellow' : 'white');
                    }
                    if (count($stats['details']) > 10) {
                        CLI::write(sprintf('... %d détail(s) supplémentaire(s)', count($stats['details']) - 10));
                    }

                }
                if (! $apply) {
                    CLI::write(PHP_EOL . 'Simulation uniquement. Relancez avec --apply pour écrire dans AMS.', 'yellow');
                }
            }

            return $erreurs > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $e) {
            if (isset($amsDb, $transactionStarted) && $apply && $transactionStarted) {
                $amsDb->transRollback();
            }
            CLI::error('Échec de l’import GPIO : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    private function optionValue(string $name): string|bool|null
    {
        $options = CLI::getOptions();
        if (array_key_exists($name, $options)) {
            return $options[$name] ?? true;
        }

        $prefix = $name . '=';
        foreach ($options as $option => $value) {
            if (str_starts_with($option, $prefix)) {
                return substr($option, strlen($prefix));
            }
        }

        return null;
    }
}
