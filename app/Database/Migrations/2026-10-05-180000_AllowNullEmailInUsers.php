<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Autorise les comptes applicatifs liés à un employé sans email connu.
 */
class AllowNullEmailInUsers extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('users', [
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $nullEmails = (int) $this->db->table('users')
            ->where('email', null)
            ->countAllResults();
        if ($nullEmails > 0) {
            throw new RuntimeException(sprintf(
                'Rollback refusé : %d compte(s) n’ont pas d’email.',
                $nullEmails
            ));
        }

        $this->forge->modifyColumn('users', [
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => false,
            ],
        ]);
    }
}
