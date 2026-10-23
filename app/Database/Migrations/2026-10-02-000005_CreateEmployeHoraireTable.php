<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Affecte un horaire spécifique à un employé sur une période donnée.
 */
class CreateEmployeHoraireTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'employe_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'horaire_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'date_debut' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'date_fin' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('employe_id', 'employes', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('horaire_id', 'tenant_horaires', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'employe_id', 'date_debut']);
        $this->forge->addKey('employe_id');
        $this->forge->addKey(['tenant_id', 'horaire_id']);

        $this->forge->createTable('employe_horaire', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('employe_horaire', true);
    }
}