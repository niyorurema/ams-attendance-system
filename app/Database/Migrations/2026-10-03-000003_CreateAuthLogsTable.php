<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée la traçabilité des tentatives d’authentification.
 */
class CreateAuthLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
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
                'null' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => false,
            ],
            'auth_source' => [
                'type' => 'ENUM',
                'constraint' => ['LOCAL', 'LDAP'],
                'null' => false,
            ],
            'statut' => [
                'type' => 'ENUM',
                'constraint' => ['SUCCESS', 'ECHEC_MDP', 'ECHEC_LDAP', 'COMPTE_INACTIF', 'ERREUR'],
                'null' => false,
            ],
            'ip_address' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => true,
            ],
            'user_agent' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'message_erreur' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->addKey(['email', 'created_at']);
        $this->forge->addKey('statut');

        $this->forge->createTable('auth_logs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('auth_logs', true);
    }
}