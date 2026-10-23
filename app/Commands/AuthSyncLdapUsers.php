<?php

namespace App\Commands;

use App\Services\LdapAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Synchronise les utilisateurs LDAP vers la base locale.
 *
 * Usage : php spark auth:sync-ldap-users {tenant_id} [--dry-run]
 */
class AuthSyncLdapUsers extends BaseCommand
{
    protected $group = 'Auth';
    protected $name = 'auth:sync-ldap-users';
    protected $description = 'Synchronise les utilisateurs AD/LDAP vers la base.';
    protected $usage = 'php spark auth:sync-ldap-users {tenant_id} [--dry-run]';
    protected $arguments = [
        'tenant_id' => 'Identifiant du tenant.',
    ];
    protected $options = [
        '--dry-run' => 'Simule sans écrire en base.',
    ];

    public function run(array $params): int
    {
        $tenantId = $params[0] ?? null;
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            CLI::error('Identifiant de tenant invalide. Usage : php spark auth:sync-ldap-users {tenant_id} [--dry-run]');

            return EXIT_ERROR;
        }

        $dryRun = CLI::getOption('dry-run') !== null;
        CLI::write(sprintf('=== Synchronisation LDAP tenant #%d ===', (int) $tenantId), 'cyan');
        if ($dryRun) {
            CLI::write('[DRY-RUN] Aucune écriture en base.', 'yellow');
        }

        try {
            $resultat = (new LdapAuthService())->syncUtilisateurs((int) $tenantId, $dryRun);

            CLI::write(sprintf('Utilisateurs AD trouvés : %d', $resultat['total']));
            CLI::write(sprintf('Créés : %d', $resultat['crees']), 'green');
            CLI::write(sprintf('Mis à jour : %d', $resultat['maj']), 'blue');
            CLI::write(sprintf('Ignorés : %d', $resultat['ignores']));
            CLI::write(sprintf('Erreurs : %d', $resultat['erreurs']), $resultat['erreurs'] > 0 ? 'red' : 'white');

            if ($dryRun) {
                CLI::write('[DRY-RUN] Relancez sans --dry-run pour appliquer ces changements.', 'yellow');
            }

            return $resultat['erreurs'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Erreur de synchronisation LDAP : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}