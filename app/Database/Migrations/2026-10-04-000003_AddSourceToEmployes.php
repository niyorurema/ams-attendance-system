<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute les colonnes de traçabilité de source externe aux employés.
 *
 * Permet de mapper un employé AMS à un utilisateur du système source GPIO.
 */
class AddSourceToEmployes extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('employes', [
            'source_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'unite_id',
            ],
            'source_systeme' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'source_id',
            ],
        ]);

        $this->db->query(
            'ALTER TABLE employes ADD INDEX idx_source_employe (source_systeme, source_id)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE employes DROP INDEX idx_source_employe');
        $this->forge->dropColumn('employes', ['source_id', 'source_systeme']);
    }
}
