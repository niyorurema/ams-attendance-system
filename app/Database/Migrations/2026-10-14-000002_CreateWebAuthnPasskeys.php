<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebAuthnPasskeys extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'webauthn_enrollment_approved' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'credential_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'credential_data' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('credential_hash');
        $this->forge->addKey(['tenant_id', 'user_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('webauthn_credentials', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id' => ['type' => 'CHAR', 'constraint' => 64],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'purpose' => ['type' => 'ENUM', 'constraint' => ['registration', 'authentication']],
            'options_json' => ['type' => 'TEXT'],
            'expires_at' => ['type' => 'DATETIME'],
            'consumed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['tenant_id', 'user_id', 'purpose', 'expires_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('webauthn_challenges', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('webauthn_challenges', true);
        $this->forge->dropTable('webauthn_credentials', true);
        $this->forge->dropColumn('users', 'webauthn_enrollment_approved');
    }
}
