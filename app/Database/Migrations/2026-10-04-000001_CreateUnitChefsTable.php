<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée les mandats des responsables d'unités organisationnelles.
 */
class CreateUnitChefsTable extends Migration
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
            'unite_id' => [
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
            'type_mandat' => [
                'type' => 'ENUM',
                'constraint' => ['TITULAIRE', 'INTERIM', 'ADJOINT', 'INTERIM_ADJOINT'],
                'default' => 'TITULAIRE',
            ],
            'date_debut' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'date_fin' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'est_actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'default' => 1,
            ],
            'source_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'source_systeme' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'source_synced_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'remarque' => [
                'type' => 'TEXT',
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
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('unite_id', 'unites_organisationnelles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addUniqueKey(['tenant_id', 'unite_id', 'user_id', 'date_debut'], 'uk_mandat');
        $this->forge->addKey(['unite_id', 'est_actif'], false, false, 'idx_unite_actif');
        $this->forge->addKey('user_id', false, false, 'idx_user');
        $this->forge->addKey(['source_systeme', 'source_id'], false, false, 'idx_source');

        $this->forge->createTable('unit_chefs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('unit_chefs', true);
    }
}
