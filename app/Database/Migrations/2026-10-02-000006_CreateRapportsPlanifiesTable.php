<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Configure les rapports automatiques destinés aux utilisateurs d’un tenant.
 */
class CreateRapportsPlanifiesTable extends Migration
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
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'nom' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => false,
            ],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['QUOTIDIEN', 'HEBDOMADAIRE', 'MENSUEL'],
                'null' => false,
                'default' => 'QUOTIDIEN',
            ],
            'heure_envoi' => [
                'type' => 'TIME',
                'null' => false,
                'default' => '07:00:00',
            ],
            'jours_semaine' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => false,
                'default' => '1,2,3,4,5',
            ],
            'format' => [
                'type' => 'ENUM',
                'constraint' => ['PDF', 'EXCEL', 'HTML'],
                'null' => false,
                'default' => 'PDF',
            ],
            'langue' => [
                'type' => 'CHAR',
                'constraint' => 2,
                'null' => false,
                'default' => 'fr',
            ],
            'perimetre_type' => [
                'type' => 'ENUM',
                'constraint' => ['EQUIPE', 'SERVICE', 'DIRECTION', 'COMMISSARIAT', 'TOUS'],
                'null' => false,
            ],
            'perimetre_unite_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'inclure_agents_bureau' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'dernier_envoi' => [
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('perimetre_unite_id', 'unites_organisationnelles', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addKey(['tenant_id', 'actif', 'heure_envoi']);
        $this->forge->addKey(['tenant_id', 'user_id']);

        $this->forge->createTable('rapports_planifies', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('rapports_planifies', true);
    }
}