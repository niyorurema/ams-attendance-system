<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute le curseur incrémental de synchronisation des pointages par capteur.
 */
class AddDernierPointageSyncToCapteurs extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('dernier_pointage_sync', 'capteurs')) {
            $this->forge->addColumn('capteurs', [
                'dernier_pointage_sync' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('dernier_pointage_sync', 'capteurs')) {
            $this->forge->dropColumn('capteurs', 'dernier_pointage_sync');
        }
    }
}