<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stocke les réglages globaux des intégrations et protège le secret LDAP.
 */
class CreateApplicationIntegrationSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'setting_key' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'setting_value' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'is_encrypted' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'updated_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
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
        $this->forge->addPrimaryKey('setting_key');
        $this->forge->createTable('application_settings', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addColumn('tenants', [
            'ldap_host_secondary' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_host_tertiary' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_bind_password_encrypted' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', [
            'ldap_host_secondary',
            'ldap_host_tertiary',
            'ldap_bind_password_encrypted',
        ]);
        $this->forge->dropTable('application_settings', true);
    }
}
