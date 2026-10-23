<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Configure le mode d’authentification d’un tenant.
 */
class TenantConfigurerAuth extends BaseCommand
{
    protected $group = 'Tenant';
    protected $name = 'tenant:configurer-auth';
    protected $description = 'Configure le mode LOCAL, LDAP ou HYBRIDE d’un tenant.';
    protected $usage = 'tenant:configurer-auth <tenant_id> --mode=LOCAL|LDAP|HYBRIDE [--dry-run|--apply]';
    protected $arguments = [
        'tenant_id' => 'Identifiant du tenant.',
    ];
    protected $options = [
        '--mode' => 'Mode cible : LOCAL, LDAP ou HYBRIDE.',
        '--dry-run' => 'Affiche le changement prévu sans écrire (mode par défaut).',
        '--apply' => 'Applique le mode indiqué.',
    ];

    public function run(array $params): int
    {
        $tenantId = $params[0] ?? null;
        $modeOption = $this->optionValue('mode');
        $mode = is_string($modeOption) ? strtoupper(trim($modeOption)) : '';
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            CLI::error('Identifiant de tenant invalide.');
            return EXIT_ERROR;
        }
        if (! in_array($mode, ['LOCAL', 'LDAP', 'HYBRIDE'], true)) {
            CLI::error('Mode invalide. Valeurs autorisées : LOCAL, LDAP, HYBRIDE.');
            return EXIT_ERROR;
        }
        $apply = CLI::getOption('apply') !== null;
        if ($apply && CLI::getOption('dry-run') !== null) {
            CLI::error('Choisissez --dry-run ou --apply, pas les deux.');
            return EXIT_ERROR;
        }

        $db = Database::connect();
        try {
            $tenant = $db->table('tenants')
                ->select('id, auth_mode')
                ->where('id', (int) $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                CLI::error('Tenant introuvable ou supprimé.');
                return EXIT_ERROR;
            }

            if ($apply) {
                $db->transBegin();
                $updated = $db->table('tenants')
                    ->where('id', (int) $tenantId)
                    ->where('deleted_at', null)
                    ->update(['auth_mode' => $mode, 'updated_at' => date('Y-m-d H:i:s')]);
                if (! $updated || $db->transStatus() === false) {
                    $db->transRollback();
                    throw new \RuntimeException('Mise à jour du mode d’authentification refusée.');
                }
                $db->transCommit();
            }

            CLI::write('Tenant : ' . (int) $tenantId);
            CLI::write('Mode actuel : ' . strtoupper((string) $tenant['auth_mode']));
            CLI::write('Mode cible : ' . $mode);
            CLI::write('Résultat : ' . ($apply ? 'appliqué' : 'simulation uniquement'));
            if (in_array($mode, ['LDAP', 'HYBRIDE'], true)) {
                CLI::write('Vérifiez la connectivité LDAP avec php spark auth:test-ldap ' . (int) $tenantId . ' avant les essais de connexion.', 'yellow');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Configuration du mode d’authentification impossible : ' . $exception->getMessage());
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
