<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée l'historique des contrats horaires individuels.
 */
class CreateEmployeContratsHoraireTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
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
            'mode_travail' => [
                'type' => 'ENUM',
                'constraint' => ['TEMPS_PLEIN', 'MI_TEMPS_MATIN', 'MI_TEMPS_APRES_MIDI', 'HORAIRE_LIBRE'],
                'null' => false,
            ],
            'heure_debut' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'heure_fin' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'jours_travail' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'date_debut' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'date_fin' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
            'fk_contrats_horaire_tenant_employe'
        );
        $this->forge->addKey(['tenant_id', 'employe_id', 'date_debut']);
        $this->forge->addKey(['tenant_id', 'employe_id', 'actif', 'date_debut']);
        $this->forge->createTable('employe_contrats_horaire', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('employe_contrats_horaire', true);
    }
}
