<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée les affectations N-N entre employés et capteurs.
 */
class CreateEmployeCapteurTable extends Migration
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
            'capteur_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'badge_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'date_affectation' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'date_retrait' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'sync_status' => [
                'type' => 'ENUM',
                'constraint' => ['SYNC', 'PENDING', 'ERROR'],
                'null' => false,
                'default' => 'PENDING',
            ],
            'derniere_sync' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('employe_id', 'employes', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('capteur_id', 'capteurs', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addUniqueKey(['tenant_id', 'employe_id', 'capteur_id']);
        $this->forge->addUniqueKey(['tenant_id', 'capteur_id', 'badge_id']);
        $this->forge->addKey(['tenant_id', 'actif']);

        $this->forge->createTable('employe_capteur', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('employe_capteur', true);
    }
}