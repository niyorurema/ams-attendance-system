<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Relie les comptes applicatifs aux employés et autorise les comptes LDAP sans mot de passe local.
 */
class AddEmployeIdToUsers extends Migration
{
    private const EMPLOYE_FOREIGN_KEY = 'fk_users_employe';
    private const EMPLOYE_INDEX = 'idx_users_employe_id';

    public function up(): void
    {
        $this->forge->addColumn('users', [
            'employe_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'auth_source' => [
                'type' => 'ENUM',
                'constraint' => ['LOCAL', 'LDAP'],
                'null' => false,
                'default' => 'LOCAL',
            ],
            'ldap_dn' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
            ],
            'ldap_derniere_sync' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->modifyColumn('users', [
            'password_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
        ]);

        $this->db->query('CREATE INDEX ' . self::EMPLOYE_INDEX . ' ON users (employe_id)');
        $this->db->query(
            'ALTER TABLE users ADD CONSTRAINT ' . self::EMPLOYE_FOREIGN_KEY
            . ' FOREIGN KEY (employe_id) REFERENCES employes(id)'
            . ' ON UPDATE RESTRICT ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        $nullPasswords = (int) $this->db->table('users')
            ->where('password_hash', null)
            ->countAllResults();

        if ($nullPasswords > 0) {
            throw new RuntimeException(sprintf(
                'Rollback refusé : %d compte(s) sans mot de passe local existent.',
                $nullPasswords
            ));
        }

        $this->db->query('ALTER TABLE users DROP FOREIGN KEY ' . self::EMPLOYE_FOREIGN_KEY);
        $this->db->query('DROP INDEX ' . self::EMPLOYE_INDEX . ' ON users');
        $this->forge->dropColumn('users', ['employe_id', 'auth_source', 'ldap_dn', 'ldap_derniere_sync']);
        $this->forge->modifyColumn('users', [
            'password_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
        ]);
    }
}