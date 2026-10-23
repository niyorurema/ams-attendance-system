<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conserve l’historique des rapports générés et envoyés.
 */
class CreateRapportsEnvoyesTable extends Migration
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
            'rapport_planifie_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'date_rapport' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'heure_envoi' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'format' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => false,
            ],
            'fichier_path' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
            ],
            'destinataire_email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => false,
            ],
            'statut' => [
                'type' => 'ENUM',
                'constraint' => ['ENVOYE', 'ECHEC', 'EN_ATTENTE'],
                'null' => false,
                'default' => 'EN_ATTENTE',
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
        $this->forge->addForeignKey('rapport_planifie_id', 'rapports_planifies', 'id', 'RESTRICT', 'SET NULL');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->addKey(['tenant_id', 'date_rapport']);
        $this->forge->addKey(['user_id', 'date_rapport']);
        $this->forge->addKey('statut');

        $this->forge->createTable('rapports_envoyes', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('rapports_envoyes', true);
    }
}