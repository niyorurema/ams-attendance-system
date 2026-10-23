<?php

namespace App\Commands;

use App\Services\LdapAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Teste la connexion LDAP d'un tenant.
 *
 * Usage : php spark auth:test-ldap {tenant_id}
 */
class AuthTestLdap extends BaseCommand
{
    protected $group = 'Auth';
    protected $name = 'auth:test-ldap';
    protected $description = 'Teste la connexion LDAP du tenant.';
    protected $usage = 'php spark auth:test-ldap {tenant_id}';
    protected $arguments = [
        'tenant_id' => 'Identifiant du tenant à tester.',
    ];

    public function run(array $params): int
    {
        $tenantId = $params[0] ?? null;
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            CLI::error('Identifiant de tenant invalide. Usage : php spark auth:test-ldap {tenant_id}');

            return EXIT_ERROR;
        }

        CLI::write(sprintf('=== Test LDAP tenant #%d ===', (int) $tenantId), 'cyan');

        try {
            $test = (new LdapAuthService())->tester((int) $tenantId);
            if (isset($test['message'])) {
                CLI::error($test['message']);

                return EXIT_ERROR;
            }

            foreach ($test['resultats'] as $host => $resultat) {
                $succes = ($resultat['statut'] ?? '') === 'OK';
                $statut = $succes ? 'OK' : 'ERREUR';
                $couleur = $succes ? 'green' : 'red';
                $message = $resultat['message'] ?? '';

                CLI::write(sprintf('  [%s] %s (%d ms) : %s', $statut, $host, $resultat['duree_ms'] ?? 0, $message), $couleur);
            }

            if ($test['success']) {
                CLI::write('Connexion LDAP opérationnelle sur au moins un serveur.', 'green');

                return EXIT_SUCCESS;
            }

            CLI::error('Échec de la connexion LDAP sur tous les serveurs configurés.');

            return EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error('Erreur lors du test LDAP : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
