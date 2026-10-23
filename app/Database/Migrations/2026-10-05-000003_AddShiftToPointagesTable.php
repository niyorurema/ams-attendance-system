<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddShiftToPointagesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('pointages', [
            'shift_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'capteur_id'],
        ]);
        $this->db->query('ALTER TABLE `pointages` ADD CONSTRAINT `fk_pointages_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE, ADD INDEX `idx_shift` (`shift_id`)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `pointages` DROP FOREIGN KEY `fk_pointages_shift_id`');
        $this->db->query('ALTER TABLE `pointages` DROP INDEX `idx_shift`');
        $this->forge->dropColumn('pointages', 'shift_id');
    }
}
