<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTimezoneAndManualPunchAudit extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "UPDATE tenants SET timezone = 'Africa/Bujumbura' WHERE timezone IS NULL OR timezone = ''"
        );
        $this->db->query(
            "ALTER TABLE tenants MODIFY timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Bujumbura'"
        );

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'employe_id' => ['type' => 'INT', 'unsigned' => true],
            'pointage_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'auth_source' => ['type' => 'VARCHAR', 'constraint' => 16],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'TEXT', 'null' => true],
            'credential_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'timezone' => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'user_id', 'created_at']);
        $this->forge->addKey('pointage_id');
        $this->forge->createTable('pointage_manual_audit', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('pointage_manual_audit', true);
        $this->db->query("ALTER TABLE tenants MODIFY timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Kigali'");
    }
}
