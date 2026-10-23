<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Porte à 360 minutes les écarts d'association restés à l'ancien défaut de 240.
 *
 * Les identifiants et horodatages sont conservés temporairement afin que down()
 * ne restaure que les lignes encore inchangées depuis cette migration.
 */
class MigrerEcartMaxAssociationParDefaut extends Migration
{
    private const TABLE_TRACE = 'tenant_parametres_pointage_000011_backup';

    public function up(): void
    {
        $this->forge->addField([
            'parametre_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'ancienne_date_modification' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'date_migration' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);
        $this->forge->addPrimaryKey('parametre_id');
        $this->forge->createTable(self::TABLE_TRACE, true, ['ENGINE' => 'InnoDB']);

        $cibles = $this->db->table('tenant_parametres_pointage')
            ->select('id, updated_at')
            ->where('cle', 'ecart_max_association_minutes')
            ->where('valeur', '240')
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $dateMigration = date('Y-m-d H:i:s');

        $this->db->transBegin();
        try {
            foreach ($cibles as $cible) {
                $parametreId = (int) $cible['id'];
                $this->db->table(self::TABLE_TRACE)->insert([
                    'parametre_id' => $parametreId,
                    'ancienne_date_modification' => $cible['updated_at'],
                    'date_migration' => $dateMigration,
                ]);
                $this->db->table('tenant_parametres_pointage')
                    ->where('id', $parametreId)
                    ->where('cle', 'ecart_max_association_minutes')
                    ->where('valeur', '240')
                    ->where('deleted_at', null)
                    ->update([
                        'valeur' => '360',
                        'updated_at' => $dateMigration,
                    ]);
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Impossible de migrer un écart maximal d’association.');
                }
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('La migration des écarts maximaux d’association a échoué.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE_TRACE)) {
            return;
        }

        $lignes = $this->db->table(self::TABLE_TRACE)
            ->get()
            ->getResultArray();

        $this->db->transBegin();
        try {
            foreach ($lignes as $ligne) {
                $this->db->table('tenant_parametres_pointage')
                    ->where('id', (int) $ligne['parametre_id'])
                    ->where('cle', 'ecart_max_association_minutes')
                    ->where('valeur', '360')
                    ->where('updated_at', $ligne['date_migration'])
                    ->where('deleted_at', null)
                    ->update([
                        'valeur' => '240',
                        'updated_at' => $ligne['ancienne_date_modification'],
                    ]);
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Impossible de restaurer un écart maximal d’association.');
                }
            }

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Le rollback des écarts maximaux d’association a échoué.');
            }
            $this->db->transCommit();
        } catch (\Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }

        $this->forge->dropTable(self::TABLE_TRACE, true);
    }
}
