<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute les colonnes de traçabilité de source externe aux unités.
 *
 * Permet de mapper une unité AMS à une entité d'un système source comme GPIO.
 */
class AddSourceToUnites extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('unites_organisationnelles', [
            'source_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'code',
            ],
            'source_systeme' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'source_id',
            ],
            'source_synced_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'source_systeme',
            ],
        ]);

        $this->db->query(
            'ALTER TABLE unites_organisationnelles ADD INDEX idx_source (source_systeme, source_id)'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE unites_organisationnelles DROP INDEX idx_source');
        $this->forge->dropColumn('unites_organisationnelles', [
            'source_id',
            'source_systeme',
            'source_synced_at',
        ]);
    }
}
