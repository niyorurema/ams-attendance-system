<?php

use App\Commands\DbVerifierCollation;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DbVerifierCollationTest extends CIUnitTestCase
{
    public function testAccepteLaCollationAttenduePourToutesLesValeurs(): void
    {
        $this->assertTrue(DbVerifierCollation::collationsConformes([
            'database' => 'utf8mb4_unicode_ci',
            'connection' => 'utf8mb4_unicode_ci',
            'tenant_creneaux_pointage' => 'utf8mb4_unicode_ci',
        ]));
    }

    public function testRefuseUneCollationDifferenteOuAucuneValeur(): void
    {
        $this->assertFalse(DbVerifierCollation::collationsConformes([
            'database' => 'utf8mb4_unicode_ci',
            'connection' => 'utf8mb4_0900_ai_ci',
        ]));
        $this->assertFalse(DbVerifierCollation::collationsConformes([]));
    }
}
