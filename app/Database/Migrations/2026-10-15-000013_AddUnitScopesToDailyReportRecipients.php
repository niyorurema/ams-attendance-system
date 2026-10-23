<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUnitScopesToDailyReportRecipients extends Migration
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
            'unite_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'user_id', 'unite_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('unite_id', 'unites_organisationnelles', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('rapport_journalier_destinataires_unites', true, ['ENGINE' => 'InnoDB']);

        $this->db->query(
            'INSERT INTO rapport_journalier_destinataires_unites (tenant_id, user_id, unite_id, created_at)
             SELECT rd.tenant_id, rd.user_id, e.unite_id, rd.created_at
             FROM rapport_journalier_destinataires rd
             LEFT JOIN users u ON u.id = rd.user_id AND u.tenant_id = rd.tenant_id
             LEFT JOIN employes e ON e.id = u.employe_id AND e.tenant_id = rd.tenant_id'
        );

        $this->forge->addColumn('rapports_envoyes', [
            'rapport_unite_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'rapport_journalier_config_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('rapports_envoyes', 'rapport_unite_id');
        $this->forge->dropTable('rapport_journalier_destinataires_unites', true);
    }
}
