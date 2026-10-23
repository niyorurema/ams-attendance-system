<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration : création de la table des pointages historiques.
 *
 * Les pointages sont les événements capturés par le dispositif ZKTeco et
 * enregistrés par le service de synchronisation. La relation à l'employé se fait
 * principalement via `badge_id`, et la sécurité multi-tenant est assurée par
 * `tenant_id` ainsi que par les index adaptés aux filtres temporels.
 */
class CreatePointagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'employe_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'badge_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'capteur_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'type_evenement' => [
                'type'       => 'ENUM',
                'constraint' => ['CHECK_IN', 'CHECK_OUT', 'BREAK_IN', 'BREAK_OUT', 'UNKNOWN'],
                'null'       => false,
                'default'    => 'UNKNOWN',
            ],
            'date_pointage' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'date_heure_locale' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'source' => [
                'type'       => 'ENUM',
                'constraint' => ['CAPTEUR', 'MANUEL', 'IMPORT'],
                'null'       => false,
                'default'    => 'CAPTEUR',
            ],
            'statut' => [
                'type'       => 'ENUM',
                'constraint' => ['VALIDE', 'INVALIDE', 'REJETE', 'SUPPRIME'],
                'null'       => false,
                'default'    => 'VALIDE',
            ],
            'raw_data' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('employe_id', 'employes', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addForeignKey('capteur_id', 'capteurs', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addKey(['tenant_id', 'date_pointage']);
        $this->forge->addKey(['tenant_id', 'badge_id', 'date_pointage']);
        $this->forge->addKey(['tenant_id', 'capteur_id', 'date_pointage']);
        $this->forge->addKey(['tenant_id', 'statut']);

        $this->forge->createTable('pointages', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('pointages', true);
    }
}
