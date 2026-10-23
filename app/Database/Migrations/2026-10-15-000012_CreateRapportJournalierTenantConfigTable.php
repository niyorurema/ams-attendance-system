<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRapportJournalierTenantConfigTable extends Migration
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
            'heure_envoi' => [
                'type' => 'TIME',
                'null' => false,
                'default' => '08:00:00',
            ],
            'format' => [
                'type' => 'ENUM',
                'constraint' => ['PDF', 'EXCEL'],
                'null' => false,
                'default' => 'PDF',
            ],
            'langue' => [
                'type' => 'CHAR',
                'constraint' => 2,
                'null' => false,
                'default' => 'fr',
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
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('rapport_journalier_configs', true, ['ENGINE' => 'InnoDB']);

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
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'user_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('rapport_journalier_destinataires', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addColumn('rapports_envoyes', [
            'contenu_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'fichier_path',
            ],
            'destinataire_nom' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
                'after' => 'destinataire_email',
            ],
            'rapport_journalier_config_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'rapport_planifie_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('rapports_envoyes', [
            'contenu_json',
            'destinataire_nom',
            'rapport_journalier_config_id',
        ]);
        $this->forge->dropTable('rapport_journalier_destinataires', true);
        $this->forge->dropTable('rapport_journalier_configs', true);
    }
}
