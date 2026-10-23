<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Crée le stockage extensible des paramètres métier de pointage par tenant.
 */
class CreateTenantParametresPointageTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'cle' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'valeur' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'type' => [
                'type' => 'ENUM',
                'constraint' => ['INT', 'BOOL', 'STRING', 'TIME', 'DECIMAL'],
                'null' => false,
                'default' => 'STRING',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'modifiable_par' => [
                'type' => 'ENUM',
                'constraint' => ['SUPER_ADMIN', 'ADMIN', 'RH'],
                'null' => false,
                'default' => 'RH',
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('tenant_parametres_pointage', true, ['ENGINE' => 'InnoDB']);

        // Garantit une seule clé active par tenant tout en autorisant son
        // remplacement après soft delete, y compris sur MySQL où NULL est répétable.
        $this->db->query(
            "ALTER TABLE `tenant_parametres_pointage`
             ADD COLUMN `cle_active` VARCHAR(100)
                 GENERATED ALWAYS AS (
                     CASE WHEN `deleted_at` IS NULL THEN `cle` ELSE NULL END
                 ) STORED,
             ADD UNIQUE KEY `uk_tenant_parametre_actif` (`tenant_id`, `cle_active`)"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('tenant_parametres_pointage', true);
    }
}
