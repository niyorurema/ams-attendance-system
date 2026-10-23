<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conserve le nombre d’enregistrements ATTLOG lus pour le prochain scan incrémental.
 */
class AddDernierPointageIndexToCapteurs extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('dernier_pointage_index', 'capteurs')) {
            $this->forge->addColumn('capteurs', [
                'dernier_pointage_index' => [
                    'type' => 'INT',
                    'constraint' => 10,
                    'unsigned' => true,
                    'null' => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('dernier_pointage_index', 'capteurs')) {
            $this->forge->dropColumn('capteurs', 'dernier_pointage_index');
        }
    }
}
