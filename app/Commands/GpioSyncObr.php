<?php

namespace App\Commands;

use App\Services\ImportGpioService;
use App\Services\ApplicationIntegrationSettings;
use App\Services\SystemJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Synchronisation serveur ETau -> AMS réservée au tenant OBR.
 */
final class GpioSyncObr extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:sync-obr';
    protected $description = 'Synchronise les employés et mandats ETau du tenant OBR.';
    protected $usage = 'gpio:sync-obr [--dry-run]';
    protected $options = [
        '--dry-run' => 'Simule la synchronisation sans modifier AMS.',
    ];

    public function run(array $params): int
    {
        $dryRun = CLI::getOption('dry-run') !== null;
        $db = null;
        $jobs = null;
        $runId = null;
        $transactionStarted = false;
        $lockAcquired = false;
        try {
            $db = Database::connect();
            $jobs = new SystemJobService($db);
            $runId = $jobs->start($this->name);
            if (! $dryRun && ! (new ApplicationIntegrationSettings($db))->gpioSyncEnabled()) {
                throw new RuntimeException('Synchronisation désactivée. Activez-la dans Paramètres > Intégrations.');
            }
            $lock = $db->query('SELECT GET_LOCK(?, 0) AS acquired', ['ams_gpio_sync_obr'])->getRowArray();
            if ((int) ($lock['acquired'] ?? 0) !== 1) {
                throw new RuntimeException('Une synchronisation OBR est déjà en cours.');
            }
            $lockAcquired = true;

            $tenant = $db->table('tenants')
                ->select('auth_mode')
                ->where('id', 1)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                throw new RuntimeException('Tenant OBR #1 introuvable ou supprimé.');
            }
            if (! $dryRun && ! in_array(strtoupper((string) ($tenant['auth_mode'] ?? '')), ['LDAP', 'HYBRIDE'], true)) {
                throw new RuntimeException('Le tenant OBR doit être configuré en mode LDAP ou HYBRIDE avant la synchronisation.');
            }
            if (! $dryRun) {
                $emailSchema = $db->query(
                    "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
                )->getRowArray();
                if (($emailSchema['IS_NULLABLE'] ?? 'NO') !== 'YES') {
                    throw new RuntimeException('users.email doit accepter NULL. Appliquez les migrations AMS avant la synchronisation.');
                }
                $db->transBegin();
                $transactionStarted = true;
            }

            $service = new ImportGpioService(null, $db);
            $resultats = [
                'hierarchie' => $service->importerHierarchie(1, $dryRun),
                'employes' => $service->synchroniserEmployes(1, $dryRun),
                'affectations' => $service->assignerEmployesAuxUnites(1, $dryRun),
                'mandats' => $service->importerChefs(1, $dryRun),
            ];
            $erreurs = array_sum(array_column($resultats, 'erreurs'));
            if (! $dryRun && ($erreurs > 0 || $db->transStatus() === false)) {
                throw new RuntimeException(sprintf(
                    'Synchronisation annulée : %d erreur(s); aucune modification AMS conservée.',
                    $erreurs
                ));
            }
            if (! $dryRun) {
                $db->transCommit();
                $transactionStarted = false;
            }

            CLI::write('=== Synchronisation ETau vers AMS (tenant OBR #1) ===', 'cyan');
            CLI::write('Mode : ' . ($dryRun ? 'simulation, aucune écriture' : 'application'), $dryRun ? 'yellow' : 'green');
            foreach ($resultats as $nom => $stats) {
                CLI::write(sprintf(
                    '%s — lus : %d | créés : %d | mis à jour : %d | ignorés : %d | erreurs : %d',
                    ucfirst($nom),
                    $stats['lues'],
                    $stats['crees'],
                    $stats['maj'],
                    $stats['ignores'],
                    $stats['erreurs']
                ));
                if (isset($stats['comptes_a_creer'])) {
                    CLI::write(sprintf('Comptes lecteurs à créer : %d', $stats['comptes_a_creer']));
                }
                foreach (array_slice($stats['details'] ?? [], 0, 5) as $detail) {
                    CLI::write('- ' . $detail, 'yellow');
                }
                foreach ($stats['raisons'] ?? [] as $raison => $nombre) {
                    CLI::write(sprintf('  %s : %d', $raison, $nombre), 'yellow');
                }
            }
            $jobStatus = $erreurs > 0 ? 'PARTIAL' : 'SUCCESS';
            $jobs->finish(
                $runId,
                $jobStatus,
                array_map(
                    static fn (array $stats): array => array_intersect_key(
                        $stats,
                        array_flip(['lues', 'crees', 'maj', 'ignores', 'erreurs'])
                    ),
                    $resultats
                ),
                $erreurs > 0 ? sprintf('%d erreur(s) lors de la synchronisation.', $erreurs) : null
            );
            if ($dryRun && ($resultats['employes']['crees'] ?? 0) > 0) {
                CLI::write(
                    'Les phases affectations/mandats de cette simulation ne voient pas les employés qui seraient créés; leurs compteurs « introuvable » seront recalculés lors de l’application.',
                    'yellow'
                );
            }
            return $erreurs > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $exception) {
            log_message('error', 'Synchronisation ETau du tenant OBR impossible : {error}', [
                'error' => $exception->getMessage(),
            ]);
            if ($runId !== null && $jobs !== null) {
                try {
                    $jobs->finish($runId, 'ERROR', [], $exception->getMessage());
                } catch (Throwable $trackingError) {
                    log_message('critical', 'Impossible de journaliser l’échec GPIO : {error}', [
                        'error' => $trackingError->getMessage(),
                    ]);
                }
            }
            if ($db !== null && $transactionStarted) {
                try {
                    $db->transRollback();
                } catch (Throwable $rollbackError) {
                    log_message('critical', 'Retour arrière GPIO impossible : {error}', [
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            CLI::error('Échec de la synchronisation ETau : ' . $exception->getMessage());
            return EXIT_ERROR;
        } finally {
            if ($lockAcquired && $db !== null) {
                try {
                    $db->query('SELECT RELEASE_LOCK(?)', ['ams_gpio_sync_obr']);
                } catch (Throwable $exception) {
                    log_message('warning', 'Libération du verrou de synchronisation OBR impossible : {error}', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }
}
