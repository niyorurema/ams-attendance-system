<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Empêche l’import GPIO de remplacer les modifications faites dans AMS.
 */
class AddAmsOverrideToOrganisation extends Migration
{
    public function up(): void
    {
        foreach (['unites_organisationnelles', 'unit_chefs'] as $table) {
            if (! $this->db->fieldExists('ams_override', $table)) {
                $this->forge->addColumn($table, [
                    'ams_override' => [
                        'type' => 'TINYINT',
                        'constraint' => 1,
                        'unsigned' => true,
                        'null' => false,
                        'default' => 0,
                    ],
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['unites_organisationnelles', 'unit_chefs'] as $table) {
            if ($this->db->fieldExists('ams_override', $table)) {
                $this->forge->dropColumn($table, 'ams_override');
            }
        }
    }
}
