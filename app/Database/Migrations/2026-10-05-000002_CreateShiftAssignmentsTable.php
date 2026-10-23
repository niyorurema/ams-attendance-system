<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateShiftAssignmentsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'employe_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'shift_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'date_debut' => ['type' => 'DATE'],
            'date_fin' => ['type' => 'DATE', 'null' => true],
            'actif' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('employe_id', 'employes', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('shift_id', 'shifts', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'employe_id', 'date_debut', 'deleted_at'], 'uk_employe_debut');
        $this->forge->addKey(['employe_id', 'date_debut', 'date_fin'], false, false, 'idx_employe_dates');
        $this->forge->addKey(['shift_id', 'date_debut', 'date_fin'], false, false, 'idx_shift_dates');
        $this->forge->createTable('shift_assignments', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('shift_assignments', true);
    }
}
