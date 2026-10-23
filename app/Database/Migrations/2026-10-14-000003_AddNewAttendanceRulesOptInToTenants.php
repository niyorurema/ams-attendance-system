<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute le commutateur tenant opt-in des nouvelles règles de pointage.
 */
class AddNewAttendanceRulesOptInToTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'actif_regles_nouvelles' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
                'comment' => 'Opt-in explicite vers le nouveau moteur de règles de pointage',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', 'actif_regles_nouvelles');
    }
}
