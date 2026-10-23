<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute le mode d’authentification et les paramètres LDAP par tenant.
 */
class AddAuthModeToTenants extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tenants', [
            'auth_mode' => [
                'type' => 'ENUM',
                'constraint' => ['LOCAL', 'LDAP', 'HYBRIDE'],
                'null' => false,
                'default' => 'LOCAL',
            ],
            'ldap_host' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_port' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => false,
                'default' => 389,
            ],
            'ldap_base_dn' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_bind_dn' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_bind_password' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'ldap_user_filter' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
                'default' => '(sAMAccountName={username})',
            ],
            'ldap_use_tls' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'ldap_attr_email' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
                'default' => 'mail',
            ],
            'ldap_attr_nom' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
                'default' => 'sn',
            ],
            'ldap_attr_prenom' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
                'default' => 'givenName',
            ],
            'ldap_attr_matricule' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
                'default' => 'employeeID',
            ],
            'ldap_derniere_verif' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'ldap_statut' => [
                'type' => 'ENUM',
                'constraint' => ['OK', 'ERREUR', 'NON_TESTE'],
                'null' => false,
                'default' => 'NON_TESTE',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tenants', [
            'auth_mode',
            'ldap_host',
            'ldap_port',
            'ldap_base_dn',
            'ldap_bind_dn',
            'ldap_bind_password',
            'ldap_user_filter',
            'ldap_use_tls',
            'ldap_attr_email',
            'ldap_attr_nom',
            'ldap_attr_prenom',
            'ldap_attr_matricule',
            'ldap_derniere_verif',
            'ldap_statut',
        ]);
    }
}