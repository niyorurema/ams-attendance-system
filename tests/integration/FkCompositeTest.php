<?php

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Throwable;

/**
 * Vérifie qu'un contrat ne peut pas référencer un employé d'un autre tenant.
 *
 * Configurez ATTENDANCE_FK_TEST_TENANT_ID et
 * ATTENDANCE_FK_TEST_OTHER_EMPLOYEE_ID sur la connexion de test avant exécution.
 *
 * @group integration
 */
final class FkCompositeTest extends CIUnitTestCase
{
    public function testLaCleEtrangereCompositeRejetteUnEmployeDUnAutreTenant(): void
    {
        if (getenv('RUN_DB_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Définissez RUN_DB_INTEGRATION_TESTS=1 pour les tests MySQL.');
        }

        $tenantId = filter_var(getenv('ATTENDANCE_FK_TEST_TENANT_ID'), FILTER_VALIDATE_INT);
        $employeId = filter_var(getenv('ATTENDANCE_FK_TEST_OTHER_EMPLOYEE_ID'), FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1 || $employeId === false || $employeId < 1) {
            $this->markTestSkipped('Configurez les identifiants de test de deux tenants différents.');
        }

        $db = Database::connect();
        if (! $db->tableExists('employe_contrats_horaire')) {
            $this->markTestSkipped('Exécutez d’abord les migrations sur la base de test.');
        }
        $employe = $db->table('employes')
            ->select('tenant_id')
            ->where('id', $employeId)
            ->get()
            ->getRowArray();
        if ($employe === null || (int) $employe['tenant_id'] === $tenantId) {
            $this->markTestSkipped('L’employé de test doit appartenir à un autre tenant.');
        }

        $db->transBegin();
        $rejeteeParLaCleEtrangere = false;
        try {
            $db->table('employe_contrats_horaire')->insert([
                'tenant_id' => $tenantId,
                'employe_id' => $employeId,
                'mode_travail' => 'TEMPS_PLEIN',
                'jours_travail' => json_encode(['LUN', 'MAR', 'MER', 'JEU', 'VEN'], JSON_THROW_ON_ERROR),
                'date_debut' => date('Y-m-d'),
                'actif' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            $rejeteeParLaCleEtrangere = str_contains(strtolower($exception->getMessage()), 'foreign key')
                || str_contains($exception->getMessage(), '1452');
        } finally {
            $db->transRollback();
        }

        $this->assertTrue($rejeteeParLaCleEtrangere, 'La FK composite doit rejeter la référence multi-tenant incohérente.');
    }

    public function testLaCleEtrangereCompositeRejetteUneProjectionPourUnEmployeDUnAutreTenant(): void
    {
        if (getenv('RUN_DB_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Définissez RUN_DB_INTEGRATION_TESTS=1 pour les tests MySQL.');
        }

        $tenantId = filter_var(getenv('ATTENDANCE_FK_TEST_TENANT_ID'), FILTER_VALIDATE_INT);
        $employeId = filter_var(getenv('ATTENDANCE_FK_TEST_OTHER_EMPLOYEE_ID'), FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1 || $employeId === false || $employeId < 1) {
            $this->markTestSkipped('Configurez les identifiants de test de deux tenants différents.');
        }

        $db = Database::connect('tests');
        if (! $db->tableExists('pointages_journee')) {
            $this->markTestSkipped('Exécutez d’abord les migrations de projection sur la base de test.');
        }
        $employe = $db->table('employes')->select('tenant_id')->where('id', $employeId)->get()->getRowArray();
        if ($employe === null || (int) $employe['tenant_id'] === $tenantId) {
            $this->markTestSkipped('L’employé de test doit appartenir à un autre tenant.');
        }

        $db->transBegin();
        $rejeteeParLaCleEtrangere = false;
        try {
            $db->table('pointages_journee')->insert([
                'tenant_id' => $tenantId,
                'employe_id' => $employeId,
                'date' => '2026-10-19',
                'source_calcul' => 'CONTRAT',
                'statut_global' => 'PRESENT',
                'heures_supplementaires' => 0,
                'details' => '{}',
                'calcule_le' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            $rejeteeParLaCleEtrangere = str_contains(strtolower($exception->getMessage()), 'foreign key')
                || str_contains($exception->getMessage(), '1452');
        } finally {
            $db->transRollback();
        }

        $this->assertTrue(
            $rejeteeParLaCleEtrangere,
            'La FK composite de la projection doit rejeter la référence multi-tenant incohérente.'
        );
    }
}
