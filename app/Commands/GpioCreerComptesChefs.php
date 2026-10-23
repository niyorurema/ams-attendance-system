<?php

namespace App\Commands;

use App\Services\ImportGpioService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Prépare ou crée les comptes AMS correspondant aux chefs GPIO.
 */
class GpioCreerComptesChefs extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:creer-comptes-chefs';
    protected $description = 'Crée les comptes AMS des chefs GPIO, en dry-run par défaut.';
    protected $usage = 'gpio:creer-comptes-chefs [--tenant=1] [--dry-run|--apply] [--allow-local-auth-mode]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS cible (défaut : 1).',
        '--dry-run' => 'Simule sans écrire (mode par défaut).',
        '--apply' => 'Applique les créations dans AMS.',
        '--allow-local-auth-mode' => 'Autorise les comptes LDAP non authentifiables tant que le tenant reste en mode LOCAL.',
        '--json' => 'Affiche le résumé structuré en JSON.',
    ];

    public function run(array $params): int
    {
        $tenantOption = $this->optionValue('tenant');
        $tenantId = $tenantOption === null
            ? 1
            : (is_string($tenantOption) ? filter_var($tenantOption, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        $apply = $this->optionValue('apply') !== null;
        $dryRun = $this->optionValue('dry-run') !== null;
        if ($apply && $dryRun) {
            CLI::error('Choisissez --dry-run ou --apply, pas les deux.');

            return EXIT_ERROR;
        }

        $amsDb = null;
        $transactionStarted = false;
        try {
            $amsDb = Database::connect();
            $tenant = $amsDb->table('tenants')
                ->select('auth_mode')
                ->where('id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                throw new RuntimeException('Tenant AMS introuvable.');
            }
            if ($apply) {
                $emailSchema = $amsDb->query(
                    "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
                )->getRowArray();
                if (($emailSchema['IS_NULLABLE'] ?? 'NO') !== 'YES') {
                    throw new RuntimeException('La colonne users.email doit accepter NULL. Exécutez d’abord la migration prévue.');
                }
                $amsDb->transBegin();
                $transactionStarted = true;
            }

            $stats = (new ImportGpioService(null, $amsDb))->creerComptesChefs($tenantId, ! $apply);
            $authMode = strtoupper((string) ($tenant['auth_mode'] ?? 'LOCAL'));
            $allowLocalAuthMode = $this->optionValue('allow-local-auth-mode') !== null;
            if (
                $apply
                && $stats['crees_avec_email'] > 0
                && ! in_array($authMode, ['LDAP', 'HYBRIDE'], true)
                && ! $allowLocalAuthMode
            ) {
                throw new RuntimeException('Le tenant n’utilise pas un mode LDAP; les comptes avec email ne seraient pas authentifiables.');
            }
            if ($apply && ($stats['erreurs'] > 0 || $amsDb->transStatus() === false)) {
                throw new RuntimeException(sprintf(
                    'Création annulée : %d erreur(s), transaction AMS annulée.',
                    $stats['erreurs']
                ));
            }
            if ($apply) {
                $amsDb->transCommit();
                $transactionStarted = false;
            }

            $report = [
                'tenant_id' => $tenantId,
                'auth_mode_tenant' => $authMode,
                'ldap_authentication_available' => in_array($authMode, ['LDAP', 'HYBRIDE'], true),
                'mode' => $apply ? 'apply' : 'dry-run',
                'resultat' => $stats,
            ];
            if ($this->optionValue('json') !== null) {
                CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return $stats['erreurs'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
            }

            CLI::write('=== Comptes des chefs GPIO ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write('Mode d’authentification du tenant : ' . $authMode);
            CLI::write('Mode : ' . ($apply ? 'APPLICATION AMS' : 'DRY-RUN (aucune écriture)'), $apply ? 'red' : 'yellow');
            CLI::write(sprintf('Chefs traités : %d', $stats['traites']));
            CLI::write(sprintf('À créer avec email (LDAP) : %d', $stats['crees_avec_email']));
            CLI::write(sprintf('À créer sans email (LOCAL, sans mot de passe) : %d', $stats['crees_sans_email']));
            CLI::write(sprintf('Comptes déjà existants : %d', $stats['deja_existants']));
            CLI::write(sprintf('Ignorés : %d | Erreurs : %d', $stats['ignores'], $stats['erreurs']));
            foreach ($stats['roles'] as $role => $count) {
                CLI::write(sprintf('- Rôle %s : %d', $role, $count));
            }
            foreach ($stats['raisons'] as $reason => $count) {
                CLI::write(sprintf('- Motif %s : %d', $reason, $count), 'yellow');
            }
            foreach ($stats['details'] as $detail) {
                CLI::write('- ' . $detail, 'yellow');
            }
            CLI::write('Aucun email, nom, matricule ou identifiant individuel n’est affiché.');

            return $stats['erreurs'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $e) {
            if ($amsDb !== null && $transactionStarted) {
                $amsDb->transRollback();
            }
            CLI::error('Échec de création des comptes chefs : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    private function optionValue(string $name): string|bool|null
    {
        $options = CLI::getOptions();
        if (array_key_exists($name, $options)) {
            return $options[$name] ?? true;
        }
        foreach ($options as $option => $value) {
            if (str_starts_with($option, $name . '=')) {
                return substr($option, strlen($name) + 1);
            }
        }

        return null;
    }
}
