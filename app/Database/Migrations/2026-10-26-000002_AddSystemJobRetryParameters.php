<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Ajoute les paramètres immuables nécessaires aux relances J-1 existantes. */
final class AddSystemJobRetryParameters extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('parameters', 'system_job_retry_requests')) {
            $this->forge->addColumn('system_job_retry_requests', [
                'parameters' => ['type' => 'LONGTEXT', 'null' => true],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('parameters', 'system_job_retry_requests')) {
            $this->forge->dropColumn('system_job_retry_requests', 'parameters');
        }
    }
}
