<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée les créneaux de règles de pointage optionnels par tenant.
 *
 * Les anciennes plages de tenant_horaires restent inchangées. Le basculement
 * global est stocké sur tenants.actif_regles_nouvelles.
 */
class CreateTenantCreneauxPointageTable extends Migration
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
            'type_creneau' => [
                'type' => 'ENUM',
                'constraint' => ['ARRIVEE', 'DEBUT_PAUSE', 'FIN_PAUSE', 'DEPART'],
                'null' => false,
            ],
            'mode' => [
                'type' => 'ENUM',
                'constraint' => ['LIMITE_TARDIVE', 'LIMITE_PRECOCE', 'PLAGE'],
                'null' => false,
            ],
            'heure_cible' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'tolerance_minutes' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
            ],
            'plage_debut' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'plage_fin' => [
                'type' => 'TIME',
                'null' => true,
            ],
            'actif' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'ordre_affichage' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
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
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->addKey(['tenant_id', 'actif', 'ordre_affichage']);
        $this->forge->createTable('tenant_creneaux_pointage', true, ['ENGINE' => 'InnoDB']);

        // MySQL autorise plusieurs NULL dans un index UNIQUE. Cette colonne
        // générée rend l'unicité effective pour un créneau non supprimé.
        $this->db->query(
            "ALTER TABLE `tenant_creneaux_pointage`
             ADD COLUMN `type_creneau_actif` VARCHAR(20)
                 GENERATED ALWAYS AS (
                     CASE WHEN `deleted_at` IS NULL THEN `type_creneau` ELSE NULL END
                 ) STORED,
             ADD UNIQUE KEY `uk_tenant_creneau_actif` (`tenant_id`, `type_creneau_actif`)"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_creneaux_pointage', true);
    }
}
