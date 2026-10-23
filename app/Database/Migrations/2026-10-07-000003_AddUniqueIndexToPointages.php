<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Empêche les doublons de pointage provenant d’un même capteur.
 *
 * La migration ne supprime et ne modifie aucun pointage. Elle échoue avant
 * toute modification si les données existantes violent la clé attendue.
 * Les événements de synchronisation ont un capteur_id non nul ; MySQL autorise
 * plusieurs valeurs NULL dans un index unique, notamment pour les saisies manuelles.
 */
class AddUniqueIndexToPointages extends Migration
{
    private const INDEX_NAME = 'uk_pointage_unique';

    public function up(): void
    {
        if (! str_contains($this->db->DBDriver, 'MySQLi')) {
            throw new RuntimeException('Cette migration d’index généré est prévue pour MySQL/MariaDB.');
        }

        $doublons = $this->db->query(
            'SELECT tenant_id, badge_id, date_pointage, COALESCE(capteur_id, 0) AS capteur_cle, COUNT(*) AS nombre
             FROM pointages
             GROUP BY tenant_id, badge_id, date_pointage, COALESCE(capteur_id, 0)
             HAVING COUNT(*) > 1
             LIMIT 10'
        )->getResultArray();

        if ($doublons !== []) {
            throw new RuntimeException(
                'Index non créé : des doublons existent (jusqu’à 10 groupes affichés) : '
                . json_encode($doublons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '. Résoudre et valider ces cas manuellement avant de relancer la migration.'
            );
        }

        $this->db->query(
            'ALTER TABLE pointages
             ADD UNIQUE KEY ' . self::INDEX_NAME . ' (tenant_id, badge_id, date_pointage, capteur_id)'
        );
    }

    public function down(): void
    {
        $indexes = $this->db->query('SHOW INDEX FROM pointages WHERE Key_name = ?', [self::INDEX_NAME])->getResultArray();
        if ($indexes !== []) {
            $this->db->query('ALTER TABLE pointages DROP INDEX ' . self::INDEX_NAME);
        }
    }
}
