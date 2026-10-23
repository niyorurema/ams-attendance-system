<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute l'index requis par les clés étrangères employé/tenant composites.
 */
class AddUniqueTenantIdToEmployes extends Migration
{
    private const INDEX_NAME = 'uniq_employes_tenant_id';

    public function up(): void
    {
        $index = $this->db->query(
            "SELECT `INDEX_NAME`
             FROM information_schema.STATISTICS
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = 'employes'
               AND `NON_UNIQUE` = 0
             GROUP BY `INDEX_NAME`
             HAVING GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX`) = 'tenant_id,id'"
        )->getRowArray();

        if ($index !== null) {
            return;
        }

        $this->db->query(
            'ALTER TABLE `employes` ADD UNIQUE KEY `' . self::INDEX_NAME . '` (`tenant_id`, `id`)'
        );
    }

    public function down(): void
    {
        $index = $this->db->query(
            'SHOW INDEX FROM `employes` WHERE `Key_name` = ?',
            [self::INDEX_NAME]
        )->getRowArray();

        if ($index !== null) {
            $this->db->query('ALTER TABLE `employes` DROP INDEX `' . self::INDEX_NAME . '`');
        }
    }
}
