<?php

namespace App\Commands;

use App\Services\LdapAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Vérifie en lecture seule la base de recherche LDAP d’un tenant.
 */
class AuthDiagnostiquerBaseLdap extends BaseCommand
{
    protected $group = 'Auth';
    protected $name = 'auth:diagnostiquer-base-ldap';
    protected $description = 'Vérifie la base de recherche LDAP et les namingContexts.';
    protected $usage = 'auth:diagnostiquer-base-ldap <tenant_id>';
    protected $arguments = [
        'tenant_id' => 'Identifiant du tenant à diagnostiquer.',
    ];

    public function run(array $params): int
    {
        $tenantId = $params[0] ?? null;
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            CLI::error('Identifiant de tenant invalide.');
            return EXIT_ERROR;
        }

        try {
            $resultats = (new LdapAuthService())->diagnostiquerBaseRecherche((int) $tenantId);
            CLI::write('=== Diagnostic de la base LDAP ===', 'cyan');
            foreach ($resultats as $resultat) {
                CLI::write('Serveur : ' . $resultat['hote']);
                CLI::write('Base configurée : ' . $resultat['base']);
                CLI::write('Source de la base : ' . $resultat['source_base']);
                CLI::write('Base trouvée : ' . ($resultat['base_trouvee'] ? 'oui' : 'non'));
                CLI::write('Naming contexts : ' . ($resultat['racines'] === [] ? 'aucun retourné' : implode(' | ', $resultat['racines'])));
                if ($resultat['erreur'] !== null) {
                    CLI::write('Diagnostic : ' . $resultat['erreur'], 'yellow');
                }
            }
            CLI::write('Diagnostic en lecture seule : aucune donnée AMS ou AD modifiée.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Diagnostic de la base LDAP impossible : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
