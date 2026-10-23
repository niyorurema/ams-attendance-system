<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée la hiérarchie organisationnelle isolée par tenant.
 */
class CreateUnitesOrganisationnellesTable extends Migration
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
            'parent_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'nom' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => false,
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
            ],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['COMMISSARIAT', 'DIRECTION', 'SERVICE', 'EQUIPE'],
                'null' => false,
            ],
            'niveau' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
            ],
            'adresse' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'telephone' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'responsable_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'ordre_affichage' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
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
        $this->forge->addForeignKey('parent_id', 'unites_organisationnelles', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('responsable_id', 'employes', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addUniqueKey(['tenant_id', 'code']);
        $this->forge->addKey(['tenant_id', 'parent_id']);
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->addKey('responsable_id');

        $this->forge->createTable('unites_organisationnelles', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('unites_organisationnelles', true);
    }
}