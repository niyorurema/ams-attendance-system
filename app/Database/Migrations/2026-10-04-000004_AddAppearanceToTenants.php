<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute les options d'identité visuelle aux tenants.
 */
class AddAppearanceToTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'slogan' => [
                'type' => 'VARCHAR',
                'constraint' => 250,
                'null' => true,
                'after' => 'logo_url',
            ],
            'primary_color' => [
                'type' => 'CHAR',
                'constraint' => 7,
                'null' => true,
                'after' => 'slogan',
            ],
            'secondary_color' => [
                'type' => 'CHAR',
                'constraint' => 7,
                'null' => true,
                'after' => 'primary_color',
            ],
            'font_family' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => true,
                'after' => 'secondary_color',
            ],
            'font_size' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => true,
                'after' => 'font_family',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', [
            'slogan',
            'primary_color',
            'secondary_color',
            'font_family',
            'font_size',
        ]);
    }
}
