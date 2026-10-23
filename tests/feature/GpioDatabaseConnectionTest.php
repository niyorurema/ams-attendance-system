<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Vérifie la connexion en lecture seule à la base source GPIO.
 *
 * Exécution : GPIO_DB_TEST_ENABLED=1 vendor/bin/phpunit tests/feature/GpioDatabaseConnectionTest.php
 */
class GpioDatabaseConnectionTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GPIO_DB_TEST_ENABLED') !== '1') {
            $this->markTestSkipped('Définissez GPIO_DB_TEST_ENABLED=1 pour tester la base source.');
        }
    }

    public function testGpioConnectionCanReadSourceTables(): void
    {
        $config = config('Database');
        $this->assertSame('10.100.10.21', $config->gpio['hostname']);
        $this->assertSame('etau', $config->gpio['database']);
        $this->assertSame('ezechiel', $config->gpio['username']);

        $db = Database::connect('gpio', false);
        $database = $db->query('SELECT DATABASE() AS database_name')->getRowArray();
        $this->assertSame($config->gpio['database'], $database['database_name'] ?? null);

        foreach (['etau_service', 'etau_utilisateur', 'etau_service_chef'] as $table) {
            $result = $db->query('SELECT 1 FROM ' . $db->protectIdentifiers($table) . ' LIMIT 1');
            $this->assertNotFalse($result, 'Lecture impossible de la table source ' . $table);
        }

        $db->close();
    }
}
