<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée les politiques horaires personnalisables par tenant.
 */
class CreateTenantHorairesTable extends Migration
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
            'nom' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'nb_pointages_attendus' => [
                'type' => 'TINYINT',
                'constraint' => 3,
                'unsigned' => true,
                'null' => false,
                'default' => 2,
            ],
            'plages_horaires' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'jours_travailles' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => false,
                'default' => '1,2,3,4,5',
            ],
            'heures_travail_normales' => [
                'type' => 'DECIMAL',
                'constraint' => '4,2',
                'null' => false,
                'default' => 8.00,
            ],
            'seuil_heures_anormal' => [
                'type' => 'DECIMAL',
                'constraint' => '4,2',
                'null' => false,
                'default' => 6.00,
            ],
            'est_defaut' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'date_debut' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'date_fin' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addKey(['tenant_id', 'est_defaut']);
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->createTable('tenant_horaires', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_horaires', true);
    }
}