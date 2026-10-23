<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddJoursTravaillesToTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'jours_travailles' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => false,
                'default' => '1,2,3,4,5',
                'after' => 'timezone',
                'comment' => 'Jours travaillés (1=Lun, 7=Dim)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', 'jours_travailles');
    }
}
