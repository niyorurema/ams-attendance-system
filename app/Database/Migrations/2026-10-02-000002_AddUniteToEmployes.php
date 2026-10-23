<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ajoute l’unité organisationnelle et le poste à chaque employé.
 */
class AddUniteToEmployes extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('unite_id', 'employes')) {
            $this->forge->addColumn('employes', [
                'unite_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'null' => true,
                ],
                'poste' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                    'null' => true,
                ],
                'est_responsable_unite' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'unsigned' => true,
                    'null' => false,
                    'default' => 0,
                ],
            ]);
        }

        $this->db->query('CREATE INDEX idx_employes_unite_id ON employes (unite_id)');
        $this->db->query(
            'ALTER TABLE employes ADD CONSTRAINT fk_employes_unite '
            . 'FOREIGN KEY (unite_id) REFERENCES unites_organisationnelles(id) '
            . 'ON UPDATE RESTRICT ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE employes DROP FOREIGN KEY fk_employes_unite');
        $this->db->query('DROP INDEX idx_employes_unite_id ON employes');
        $this->forge->dropColumn('employes', ['unite_id', 'poste', 'est_responsable_unite']);
    }
}