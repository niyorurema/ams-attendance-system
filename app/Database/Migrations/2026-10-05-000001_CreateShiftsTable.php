<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateShiftsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'nom' => ['type' => 'VARCHAR', 'constraint' => 100],
            'code' => ['type' => 'VARCHAR', 'constraint' => 20],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'heure_debut' => ['type' => 'TIME'],
            'heure_fin' => ['type' => 'TIME'],
            'couleur' => ['type' => 'VARCHAR', 'constraint' => 7, 'default' => '#3b82f6'],
            'ordre_affichage' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'actif' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'code', 'deleted_at'], 'uk_tenant_code');
        $this->forge->addKey(['tenant_id', 'actif'], false, false, 'idx_tenant_actif');
        $this->forge->createTable('shifts', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('shifts', true);
    }
}
