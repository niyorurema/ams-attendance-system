<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée la projection journalière recalculable des présences.
 *
 * Cette table est un cache; les pointages bruts restent la source de vérité.
 * Priorité d'agrégation : ABSENT > HORS_CRENEAU > INCOMPLET > RETARD >
 * ANTICIPE > ANORMAL > PRESENT.
 * Le statut global reprend le statut d'étape non-OK le plus prioritaire.
 */
class CreatePointagesJourneeTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'employe_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'source_calcul' => [
                'type' => 'ENUM',
                'constraint' => ['SHIFT', 'CONTRAT', 'HORAIRE_INDIVIDUEL', 'TENANT_DEFAUT'],
                'null' => false,
            ],
            'heure_arrivee' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'statut_arrivee' => [
                'type' => 'ENUM',
                'constraint' => ['OK', 'RETARD', 'ABSENT', 'INCOMPLET', 'HORS_CRENEAU'],
                'null' => true,
            ],
            'heure_debut_pause' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'statut_debut_pause' => [
                'type' => 'ENUM',
                'constraint' => ['OK', 'ANORMAL', 'INCOMPLET', 'HORS_CRENEAU'],
                'null' => true,
            ],
            'heure_fin_pause' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'statut_fin_pause' => [
                'type' => 'ENUM',
                'constraint' => ['OK', 'RETARD', 'INCOMPLET', 'HORS_CRENEAU'],
                'null' => true,
            ],
            'heure_depart' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'statut_depart' => [
                'type' => 'ENUM',
                'constraint' => ['OK', 'ANTICIPE', 'INCOMPLET', 'HORS_CRENEAU'],
                'null' => true,
            ],
            'heures_travaillees' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => true,
            ],
            'heures_supplementaires' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => false,
                'default' => 0,
            ],
            'statut_global' => [
                'type' => 'ENUM',
                'constraint' => ['PRESENT', 'RETARD', 'ABSENT', 'INCOMPLET', 'HORS_CRENEAU', 'ANTICIPE', 'ANORMAL'],
                'null' => false,
                'default' => 'PRESENT',
            ],
            'details' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'calcule_le' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey(
            ['tenant_id', 'employe_id'],
            'employes',
            ['tenant_id', 'id'],
            'CASCADE',
            'CASCADE',
            'fk_pointages_journee_tenant_employe'
        );
        $this->forge->addUniqueKey(['tenant_id', 'employe_id', 'date'], 'uk_pointages_journee');
        $this->forge->addKey(['tenant_id', 'date', 'statut_global'], false, false, 'idx_pointages_journee_statut');
        $this->forge->addKey(['tenant_id', 'employe_id', 'date'], false, false, 'idx_pointages_journee_employe');
        $this->forge->createTable('pointages_journee', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('pointages_journee', true);
    }
}
