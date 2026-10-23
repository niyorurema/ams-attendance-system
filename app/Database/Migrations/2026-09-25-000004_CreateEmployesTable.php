<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration : création de la table des employés.
 *
 * Chaque employé appartient à un tenant et est associé à un badge_id ZKTeco
 * qui sert de clé de correspondance avec les pointages enregistrés par le
 * capteur. Les deux identifiants sont conservés :
 * - `badge_id` : identifiant numérique utilisé par les pointages
 * - `user_id_capteur` : identifiant texte du capteur (code RH / user id)
 *
 * Cela permet de rester compatible avec la logique métier et les données d'un
 * capteur ZKTeco qui expose parfois des identifiants doublés (UID + USERID).
 */
class CreateEmployesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'matricule' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => false,
            ],
            'badge_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => false,
            ],
            'user_id_capteur' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'nom' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'prenom' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],
            'telephone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'date_embauche' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'date_depart' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'genre' => [
                'type'       => 'ENUM',
                'constraint' => ['M', 'F', 'AUTRE'],
                'null'       => true,
            ],
            'photo_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'empreinte_enrolee' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
            ],
            'nb_empreintes' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
            ],
            'role_capteur' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 0,
            ],
            'actif' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'null'       => false,
                'default'    => 1,
            ],
            'derniere_sync' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addUniqueKey(['tenant_id', 'matricule']);
        $this->forge->addUniqueKey(['tenant_id', 'badge_id']);
        $this->forge->addKey(['tenant_id', 'actif']);
        $this->forge->addKey(['tenant_id', 'nom', 'prenom']);

        $this->forge->createTable('employes', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('employes', true);
    }
}
