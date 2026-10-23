<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Ajoute les rôles hiérarchiques utilisés par les comptes du tenant.
 */
class ExpandUserRoles extends Migration
{
    private const ROLES = [
        'admin',
        'rh',
        'superviseur',
        'chef_service',
        'directeur',
        'commissaire',
        'lecteur',
    ];

    public function up(): void
    {
        $this->forge->modifyColumn('users', [
            'role' => [
                'type' => 'ENUM',
                'constraint' => self::ROLES,
                'null' => false,
                'default' => 'lecteur',
            ],
        ]);
    }

    public function down(): void
    {
        $unsupportedRoles = (int) $this->db->table('users')
            ->whereNotIn('role', ['admin', 'rh', 'superviseur', 'lecteur'])
            ->countAllResults();

        if ($unsupportedRoles > 0) {
            throw new RuntimeException(sprintf(
                'Rollback refusé : %d utilisateur(s) utilisent un rôle qui ne peut être supprimé.',
                $unsupportedRoles
            ));
        }

        $this->forge->modifyColumn('users', [
            'role' => [
                'type' => 'ENUM',
                'constraint' => ['admin', 'rh', 'superviseur', 'lecteur'],
                'null' => false,
                'default' => 'lecteur',
            ],
        ]);
    }
}
