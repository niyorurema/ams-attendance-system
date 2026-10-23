<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration : création de la table des capteurs d'empreintes.
 *
 * La table `capteurs` centralise la configuration réseau et les paramètres de
 * synchronisation pour les dispositifs ZKTeco/Nymbiar. Chaque capteur appartient
 * à un tenant et est interrogé par le service ZKTecoService.
 *
 * Recommandation : conserver `com_key` et `derniere_erreur` pour permettre les
 * diagnostics réseau et le suivi des échecs de connexion sans devoir remonter
 * vers un log externe.
 */
class CreateCapteursTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'nom' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'numero_serie' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'adresse_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => false,
            ],
            'port' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 4370,
            ],
            'type_reseau' => [
                'type'       => 'ENUM',
                'constraint' => ['LAN', 'NETBIRD'],
                'null'       => false,
                'default'    => 'LAN',
            ],
            'com_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'firmware' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'platform' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'statut' => [
                'type'       => 'ENUM',
                'constraint' => ['ACTIF', 'INACTIF', 'HORS_LIGNE'],
                'null'       => false,
                'default'    => 'ACTIF',
            ],
            'dernier_scan' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'derniere_sync_users' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'derniere_sync_attendance' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'dernier_pointage_sync' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'timeout_secondes' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 60,
            ],
            'derniere_erreur' => [
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
        $this->forge->addUniqueKey(['tenant_id', 'nom']);
        $this->forge->addUniqueKey(['tenant_id', 'adresse_ip', 'port']);
        $this->forge->addKey(['tenant_id', 'statut']);
        $this->forge->addKey(['tenant_id', 'type_reseau']);

        $this->forge->createTable('capteurs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('capteurs', true);
    }
}
