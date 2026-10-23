<?php

namespace App\Commands;

use App\Database\Seeds\DemoAttendanceRules;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Supprime exclusivement les données du tenant géré par le seeder de démonstration.
 */
class DemoNettoyer extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'demo:nettoyer';
    protected $description = 'Supprime les données isolées du tenant de démonstration DEMO.';
    protected $usage = 'php spark demo:nettoyer [--force]';
    protected $options = [
        '--force' => 'Supprimer sans demander de confirmation.',
    ];

    /** @var list<string> */
    private const TABLES_AVANT_EMPLOYES = [
        'auth_logs',
        'webauthn_challenges',
        'webauthn_credentials',
        'rapports_envoyes',
        'rapports_planifies',
        'pointage_manual_audit',
        'pointages_journee',
        'employe_contrats_horaire',
        'pointages',
        'employe_capteur',
        'employe_horaire',
        'shift_assignments',
        'sync_logs',
        'tenant_creneaux_pointage',
        'tenant_parametres_pointage',
        'jours_feries',
        'tenant_horaires',
        'shifts',
        'unit_chefs',
        'users',
        'capteurs',
    ];

    public function run(array $params): int
    {
        $db = Database::connect();
        $transactionStarted = false;

        try {
            $tenant = $db->table('tenants')
                ->where('id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                CLI::write('Aucun tenant #999 à nettoyer.');

                return EXIT_SUCCESS;
            }
            if ($tenant['slug'] !== DemoAttendanceRules::TENANT_DEMO_SLUG
                || $tenant['notes'] !== DemoAttendanceRules::MARQUEUR_TENANT
            ) {
                throw new RuntimeException('Refus de suppression : tenant #999 non marqué comme tenant DEMO géré par le seeder.');
            }

            $this->afficherResume($db);
            if ($this->option('force') === null) {
                $confirmation = CLI::prompt(
                    'Supprimer définitivement toutes les données du tenant DEMO #999 ?',
                    ['non', 'oui']
                );
                if ($confirmation !== 'oui') {
                    CLI::write('Nettoyage annulé.');

                    return EXIT_SUCCESS;
                }
            }

            $db->transBegin();
            $transactionStarted = true;
            $this->supprimerDonneesTenant($db);
            $this->supprimerUnitesEnfantsDAbord($db);
            $this->supprimerEmployes($db);
            $this->verifierTenantSansDonnees($db);
            $db->table('tenants')
                ->where('id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->where('slug', DemoAttendanceRules::TENANT_DEMO_SLUG)
                ->where('notes', DemoAttendanceRules::MARQUEUR_TENANT)
                ->delete();

            if ($db->transStatus() === false || $db->table('tenants')->where('id', DemoAttendanceRules::TENANT_DEMO_ID)->countAllResults() !== 0) {
                throw new RuntimeException('La suppression du tenant DEMO n’a pas abouti.');
            }
            $db->transCommit();
            $transactionStarted = false;
            CLI::write('Tenant DEMO #999 et ses données supprimés.');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $db->transRollback();
            }
            CLI::error('Échec du nettoyage : ' . $exception->getMessage());

            return EXIT_ERROR;
        }
    }

    protected function option(string $name): string|bool|null
    {
        return CLI::getOption($name);
    }

    private function afficherResume(BaseConnection $db): void
    {
        CLI::write('Données qui seront supprimées du tenant DEMO #999 :');
        foreach (self::TABLES_AVANT_EMPLOYES as $table) {
            if (! $db->tableExists($table)) {
                continue;
            }
            $nombre = $db->table($table)
                ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->countAllResults();
            if ($nombre > 0) {
                CLI::write(sprintf('  %s : %d', $table, $nombre));
            }
        }
        $employes = $db->table('employes')
            ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
            ->countAllResults();
        CLI::write(sprintf('  employes : %d', $employes));
        $unites = $db->table('unites_organisationnelles')
            ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
            ->countAllResults();
        CLI::write(sprintf('  unites_organisationnelles : %d', $unites));
    }

    private function supprimerDonneesTenant(BaseConnection $db): void
    {
        foreach (self::TABLES_AVANT_EMPLOYES as $table) {
            if ($db->tableExists($table) && in_array('tenant_id', $db->getFieldNames($table), true)) {
                $db->table($table)->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)->delete();
            }
        }
    }

    private function supprimerUnitesEnfantsDAbord(BaseConnection $db): void
    {
        $table = 'unites_organisationnelles';
        if (! $db->tableExists($table)) {
            return;
        }

        do {
            $feuilles = $db->query(
                'SELECT unite.id
                 FROM unites_organisationnelles unite
                 WHERE unite.tenant_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM unites_organisationnelles enfant
                       WHERE enfant.parent_id = unite.id AND enfant.tenant_id = unite.tenant_id
                   )',
                [DemoAttendanceRules::TENANT_DEMO_ID]
            )->getResultArray();
            if ($feuilles === []) {
                $restantes = $db->table($table)
                    ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                    ->countAllResults();
                if ($restantes > 0) {
                    throw new RuntimeException('Impossible de supprimer les unités : la hiérarchie contient un cycle ou une référence inattendue.');
                }

                return;
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $feuilles);
            $db->table($table)->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)->whereIn('id', $ids)->delete();
        } while (true);
    }

    private function supprimerEmployes(BaseConnection $db): void
    {
        $db->table('employes')
            ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
            ->delete();
    }

    private function verifierTenantSansDonnees(BaseConnection $db): void
    {
        $tables = $db->query(
            'SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
            ['tenant_id']
        )->getResultArray();
        foreach ($tables as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (! $db->tableExists($table)) {
                continue;
            }
            $nombre = $db->table($table)
                ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->countAllResults();
            if ($nombre > 0) {
                throw new RuntimeException(sprintf(
                    'Nettoyage interrompu : %d ligne(s) du tenant #999 subsistent dans %s.',
                    $nombre,
                    $table
                ));
            }
        }
    }
}
