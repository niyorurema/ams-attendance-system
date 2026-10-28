<?php

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;
use Config\Database;
use Config\Migrations;
use RuntimeException;

trait AttendanceDatabaseTestTrait
{
    protected BaseConnection $attendanceDb;
    protected int $tenantId;
    protected int $employeId;
    protected bool $attendanceTransactionStarted = false;

    protected function setUpAttendanceDatabase(): void
    {
        $this->attendanceDb = Database::connect('tests');
        $migrations = new MigrationRunner(config(Migrations::class), 'tests');
        $migrations->setNamespace('App');
        if (! $migrations->latest()) {
            throw new RuntimeException('Impossible de préparer le schéma applicatif de la base ams_test.');
        }
        foreach (['tenants', 'employes', 'employe_contrats_horaire', 'tenant_creneaux_pointage', 'tenant_regles_pointage_versions', 'pointages', 'pointages_journee'] as $table) {
            if (! $this->attendanceDb->tableExists($table)) {
                throw new RuntimeException('Le schéma applicatif de la base ams_test est incomplet : table ' . $table . ' absente.');
            }
        }

        $this->attendanceDb->transBegin();
        $this->attendanceTransactionStarted = true;
        $this->tenantId = $this->creerTenant();
        $this->employeId = $this->creerEmploye($this->tenantId);
    }

    protected function tearDownAttendanceDatabase(): void
    {
        if (isset($this->attendanceDb) && $this->attendanceTransactionStarted) {
            $this->attendanceDb->transRollback();
            $this->attendanceTransactionStarted = false;
        }
    }

    protected function creerTenant(): int
    {
        $suffixe = bin2hex(random_bytes(8));
        $this->attendanceDb->table('tenants')->insert([
            'name' => 'Test présence ' . $suffixe,
            'slug' => 'test-presence-' . $suffixe,
            'timezone' => 'Africa/Bujumbura',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->attendanceDb->insertID();
    }

    protected function creerEmploye(int $tenantId): int
    {
        $suffixe = bin2hex(random_bytes(8));
        $this->attendanceDb->table('employes')->insert([
            'tenant_id' => $tenantId,
            'matricule' => 'TEST-' . $suffixe,
            'badge_id' => random_int(100000, 999999999),
            'nom' => 'Test',
            'prenom' => $suffixe,
            'actif' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->attendanceDb->insertID();
    }
}
