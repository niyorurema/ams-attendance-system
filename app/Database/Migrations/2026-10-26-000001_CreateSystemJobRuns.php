<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Conserve l’état récent des traitements planifiés et les demandes de relance. */
final class CreateSystemJobRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'task_key' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
            'scope_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'started_at' => ['type' => 'DATETIME', 'null' => false],
            'heartbeat_at' => ['type' => 'DATETIME', 'null' => false],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'summary' => ['type' => 'LONGTEXT', 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'requested_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['task_key', 'started_at']);
        $this->forge->addKey(['status', 'heartbeat_at']);
        $this->forge->createTable('system_job_runs', true, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'run_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => false],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'PENDING'],
            'requested_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'requested_at' => ['type' => 'DATETIME', 'null' => false],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'parameters' => ['type' => 'LONGTEXT', 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('run_id');
        $this->forge->addKey(['status', 'requested_at']);
        $this->forge->addKey(['finished_at']);
        $this->forge->addForeignKey('run_id', 'system_job_runs', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('system_job_retry_requests', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('system_job_retry_requests', true);
        $this->forge->dropTable('system_job_runs', true);
    }
}
