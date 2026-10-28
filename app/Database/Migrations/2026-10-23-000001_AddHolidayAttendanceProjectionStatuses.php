<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Permet de persister les journées fériées dans la projection quotidienne. */
class AddHolidayAttendanceProjectionStatuses extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('pointages_journee', [
            'source_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['SHIFT', 'CONTRAT', 'HORAIRE_INDIVIDUEL', 'TENANT_DEFAUT', 'JOUR_FERIE'],
                'null' => false,
            ],
            'statut_global' => [
                'type' => 'ENUM',
                'constraint' => ['PRESENT', 'RETARD', 'ABSENT', 'INCOMPLET', 'HORS_CRENEAU', 'ANTICIPE', 'ANORMAL', 'FERIE', 'EN_COURS'],
                'null' => false,
                'default' => 'PRESENT',
            ],
        ]);
    }

    public function down(): void
    {
        $projectionsFeriees = (int) $this->db->table('pointages_journee')
            ->groupStart()
                ->where('source_calcul', 'JOUR_FERIE')
                ->orWhereIn('statut_global', ['FERIE', 'EN_COURS'])
            ->groupEnd()
            ->countAllResults();
        if ($projectionsFeriees > 0) {
            throw new RuntimeException(sprintf(
                'Rollback refusé : %d projection(s) utilisent un statut indisponible après suppression de cette migration.',
                $projectionsFeriees
            ));
        }

        $this->forge->modifyColumn('pointages_journee', [
            'source_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['SHIFT', 'CONTRAT', 'HORAIRE_INDIVIDUEL', 'TENANT_DEFAUT'],
                'null' => false,
            ],
            'statut_global' => [
                'type' => 'ENUM',
                'constraint' => ['PRESENT', 'RETARD', 'ABSENT', 'INCOMPLET', 'HORS_CRENEAU', 'ANTICIPE', 'ANORMAL'],
                'null' => false,
                'default' => 'PRESENT',
            ],
        ]);
    }
}