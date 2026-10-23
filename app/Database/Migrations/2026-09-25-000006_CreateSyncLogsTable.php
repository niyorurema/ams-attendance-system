<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration : création de la table des journaux de synchronisation.
 *
 * La table `sync_logs` sert à enregistrer les opérations de synchronisation des
 * utilisateurs et des pointages avec les capteurs ZKTeco. Elle est essentielle
 * pour diagnostiquer les échecs, les timeouts, les doublons et les anomalies.
 */
class CreateSyncLogsTable extends Migration
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
            'capteur_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'type_operation' => [
                'type'       => 'ENUM',
                'constraint' => ['SYNC_USERS', 'SYNC_ATTENDANCE', 'SCAN_DEVICE', 'PING', 'OTHER'],
                'null'       => false,
                'default'    => 'OTHER',
            ],
            'statut' => [
                'type'       => 'ENUM',
                'constraint' => ['SUCCESS', 'ERROR', 'PARTIAL', 'TIMEOUT'],
                'null'       => false,
                'default'    => 'SUCCESS',
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'details' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'nombre_utilisateurs' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'nombre_pointages' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'duree_ms' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
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
        $this->forge->addForeignKey('capteur_id', 'capteurs', 'id', 'SET NULL', 'RESTRICT');
        $this->forge->addKey(['tenant_id', 'type_operation', 'created_at']);
        $this->forge->addKey(['tenant_id', 'statut', 'created_at']);
        $this->forge->addKey(['tenant_id', 'capteur_id', 'created_at']);

        $this->forge->createTable('sync_logs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('sync_logs', true);
    }
}
