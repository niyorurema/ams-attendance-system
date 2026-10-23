<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute le statut calculé indépendamment de la validité technique du pointage.
 */
class AddStatutCalculToPointages extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pointages', [
            'statut_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['VALIDE', 'RETARD', 'ANORMAL', 'UNKNOWN'],
                'null' => false,
                'default' => 'VALIDE',
                'after' => 'type_evenement',
            ],
        ]);
        $this->db->query('ALTER TABLE pointages ADD INDEX idx_statut_calcul (tenant_id, statut_calcul)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE pointages DROP INDEX idx_statut_calcul');
        $this->forge->dropColumn('pointages', 'statut_calcul');
    }
}
