<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJoursFeriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'date' => ['type' => 'DATE', 'null' => false],
            'nom' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['NATIONAL', 'RELIGIEUX', 'PERSONNALISE', 'CONGE'],
                'default' => 'PERSONNALISE',
            ],
            'recurrent' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'travaille' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'description' => ['type' => 'TEXT', 'null' => true],
            'actif' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'date', 'deleted_at']);
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->createTable('jours_feries');
    }

    public function down(): void
    {
        $this->forge->dropTable('jours_feries', true);
    }
}
