<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Vérifie la collation effective de la base, de la connexion et des tables de pointage.
 */
class DbVerifierCollation extends BaseCommand
{
    protected $group = 'Base de données';
    protected $name = 'db:verifier-collation';
    protected $description = 'Vérifie charset/collation de la base et des tables de pointage.';
    protected $usage = 'db:verifier-collation';

    private const COLLATION_ATTENDUE = 'utf8mb4_unicode_ci';
    private const TABLES_CIBLE = [
        'tenant_creneaux_pointage',
        'employe_contrats_horaire',
        'pointages_journee',
        'tenant_parametres_pointage',
    ];

    /**
     * @param array<string, string|null> $collations
     */
    public static function collationsConformes(array $collations): bool
    {
        if ($collations === []) {
            return false;
        }
        foreach ($collations as $collation) {
            if (strtolower((string) $collation) !== self::COLLATION_ATTENDUE) {
                return false;
            }
        }

        return true;
    }

    public function run(array $params): int
    {
        try {
            $db = Database::connect();
            $session = $db->query(
                'SELECT
                    @@character_set_database AS character_set_database,
                    @@collation_database AS collation_database,
                    @@character_set_connection AS character_set_connection,
                    @@collation_connection AS collation_connection'
            )->getRowArray();
            if (! is_array($session)) {
                throw new \RuntimeException('La base n’a retourné aucune information de collation.');
            }

            CLI::write('Base de données et connexion :');
            foreach ([
                'character_set_database',
                'collation_database',
                'character_set_connection',
                'collation_connection',
            ] as $key) {
                CLI::write(sprintf('  %s : %s', $key, (string) ($session[$key] ?? 'indisponible')));
            }

            $tables = $db->query(
                'SELECT TABLE_NAME, TABLE_COLLATION
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN (?, ?, ?, ?)',
                self::TABLES_CIBLE
            )->getResultArray();
            $collationsTables = [];
            foreach ($tables as $table) {
                $nom = (string) $table['TABLE_NAME'];
                $collationsTables[$nom] = (string) $table['TABLE_COLLATION'];
            }

            CLI::write('Tables du nouveau paramétrage :');
            foreach (self::TABLES_CIBLE as $nom) {
                if (! array_key_exists($nom, $collationsTables)) {
                    CLI::write('  ' . $nom . ' : absente (migrations non exécutées)', 'yellow');
                    continue;
                }
                CLI::write(sprintf('  %s : %s', $nom, $collationsTables[$nom]));
            }

            $collations = [
                'collation_database' => (string) ($session['collation_database'] ?? ''),
                'collation_connection' => (string) ($session['collation_connection'] ?? ''),
                ...$collationsTables,
            ];
            if (! self::collationsConformes($collations)) {
                CLI::error('WARNING : une ou plusieurs collations diffèrent de ' . self::COLLATION_ATTENDUE . '.');

                return EXIT_ERROR;
            }

            CLI::write('Collation conforme : ' . self::COLLATION_ATTENDUE, 'green');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Impossible de vérifier la collation : ' . $exception->getMessage());

            return EXIT_ERROR;
        }
    }
}
