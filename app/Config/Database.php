<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => 'root',
        'password'     => '',
        'database'     => 'ams',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    //    /**
    //     * Sample database connection for SQLite3.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'database'    => 'database.db',
    //        'DBDriver'    => 'SQLite3',
    //        'DBPrefix'    => '',
    //        'DBDebug'     => true,
    //        'swapPre'     => '',
    //        'failover'    => [],
    //        'foreignKeys' => true,
    //        'busyTimeout' => 1000,
    //        'synchronous' => null,
    //        'dateFormat'  => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for Postgre.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => '',
    //        'hostname'   => 'localhost',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'database'   => 'ci4',
    //        'schema'     => 'public',
    //        'DBDriver'   => 'Postgre',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'utf8',
    //        'swapPre'    => '',
    //        'failover'   => [],
    //        'port'       => 5432,
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for SQLSRV.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => '',
    //        'hostname'   => 'localhost',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'database'   => 'ci4',
    //        'schema'     => 'dbo',
    //        'DBDriver'   => 'SQLSRV',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'utf8',
    //        'swapPre'    => '',
    //        'encrypt'    => false,
    //        'failover'   => [],
    //        'port'       => 1433,
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for OCI8.
    //     *
    //     * You may need the following environment variables:
    //     *   NLS_LANG                = 'AMERICAN_AMERICA.UTF8'
    //     *   NLS_DATE_FORMAT         = 'YYYY-MM-DD HH24:MI:SS'
    //     *   NLS_TIMESTAMP_FORMAT    = 'YYYY-MM-DD HH24:MI:SS'
    //     *   NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS'
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => 'localhost:1521/FREEPDB1',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'DBDriver'   => 'OCI8',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'AL32UTF8',
    //        'swapPre'    => '',
    //        'failover'   => [],
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    /**
     * This database connection is used when running PHPUnit database tests.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => '127.0.0.1',
        'username'    => '',
        'password'    => '',
        'database'    => ':memory:',
        'DBDriver'    => 'SQLite3',
        'DBPrefix'    => 'db_',  // Needed to ensure we're working correctly with prefixes live. DO NOT REMOVE FOR CI DEVS
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'DBCollat'    => '',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => true,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'synchronous' => null,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Connexion MySQL isolée utilisée uniquement par les tests d’intégration concurrents.
     *
     * @var array<string, mixed>
     */
    public array $mysqltests = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => 'ams_test',
        'password'     => '',
        'database'     => 'ams_test',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * Connexion à la base source GPIO (OBR) - serveur séparé.
     *
     * Utilisée en lecture seule pour importer la hiérarchie organisationnelle
     * et les utilisateurs depuis GPIO.
     *
     * @var array<string, mixed>
     */
    public array $gpio = [
        'DSN'          => '',
        'hostname'     => '',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Les variables .env du projet remplacent les valeurs par défaut locales.
        $this->default['hostname'] = env('database.default.hostname', $this->default['hostname']);
        $this->default['username'] = env('database.default.username', $this->default['username']);
        $this->default['password'] = env('database.default.password', $this->default['password']);
        $this->default['database'] = env('database.default.database', $this->default['database']);
        $this->default['DBDriver'] = env('database.default.DBDriver', $this->default['DBDriver']);
        $this->default['DBPrefix'] = env('database.default.DBPrefix', $this->default['DBPrefix']);
        $this->default['charset'] = env('database.default.charset', $this->default['charset']);
        $this->default['DBCollat'] = env('database.default.DBCollat', $this->default['DBCollat']);
        $this->default['port'] = (int) env('database.default.port', $this->default['port']);
        $this->default['encrypt'] = filter_var(env('database.default.encrypt', $this->default['encrypt']), FILTER_VALIDATE_BOOLEAN);
        $this->default['compress'] = filter_var(env('database.default.compress', $this->default['compress']), FILTER_VALIDATE_BOOLEAN);
        $this->default['strictOn'] = filter_var(env('database.default.strictOn', $this->default['strictOn']), FILTER_VALIDATE_BOOLEAN);
        if ($this->default['DBDriver'] === 'MySQLi') {
            $this->default['DBDriver'] = 'App\\Database\\MySQLi';
        }

        $this->tests['hostname'] = env('database.tests.hostname', $this->tests['hostname']);
        $this->tests['username'] = env('database.tests.username', $this->tests['username']);
        $this->tests['password'] = env('database.tests.password', $this->tests['password']);
        $this->tests['database'] = env('database.tests.database', $this->tests['database']);
        $this->tests['DBDriver'] = env('database.tests.DBDriver', $this->tests['DBDriver']);
        $this->tests['DBPrefix'] = env('database.tests.DBPrefix', $this->tests['DBPrefix']);
        $this->tests['charset'] = env('database.tests.charset', $this->tests['charset']);
        $this->tests['DBCollat'] = env('database.tests.DBCollat', $this->tests['DBCollat']);
        $this->tests['port'] = (int) env('database.tests.port', $this->tests['port']);
        $this->tests['encrypt'] = filter_var(env('database.tests.encrypt', $this->tests['encrypt']), FILTER_VALIDATE_BOOLEAN);
        $this->tests['compress'] = filter_var(env('database.tests.compress', $this->tests['compress']), FILTER_VALIDATE_BOOLEAN);
        $this->tests['strictOn'] = filter_var(env('database.tests.strictOn', $this->tests['strictOn']), FILTER_VALIDATE_BOOLEAN);
        if ($this->tests['DBDriver'] === 'MySQLi') {
            $this->tests['DBDriver'] = 'App\\Database\\MySQLi';
        }

        $this->mysqltests['hostname'] = env('AMS_MYSQL_TEST_HOST', $this->mysqltests['hostname']);
        $this->mysqltests['username'] = env('AMS_MYSQL_TEST_USER', $this->mysqltests['username']);
        $this->mysqltests['password'] = env('AMS_MYSQL_TEST_PASSWORD', $this->mysqltests['password']);
        $this->mysqltests['database'] = env('AMS_MYSQL_TEST_DATABASE', $this->mysqltests['database']);
        $this->mysqltests['DBPrefix'] = env('AMS_MYSQL_TEST_PREFIX', $this->mysqltests['DBPrefix']);
        $this->mysqltests['port'] = (int) env('AMS_MYSQL_TEST_PORT', $this->mysqltests['port']);
        $this->mysqltests['DBDriver'] = 'App\\Database\\MySQLi';

        $this->gpio['hostname'] = env('GPIO_DB_HOSTNAME', $this->gpio['hostname']);
        $this->gpio['username'] = env('GPIO_DB_USERNAME', $this->gpio['username']);
        $this->gpio['password'] = env('GPIO_DB_PASSWORD', $this->gpio['password']);
        $this->gpio['database'] = env('GPIO_DB_DATABASE', $this->gpio['database']);
        $this->gpio['port'] = (int) env('GPIO_DB_PORT', $this->gpio['port']);

        // Ensure that we always set the database group to 'tests' if
        // we are currently running an automated test suite, so that
        // we don't overwrite live data on accident.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}
