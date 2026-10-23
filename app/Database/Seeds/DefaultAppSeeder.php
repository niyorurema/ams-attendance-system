<?php

namespace App\Database\Seeds;

use App\Models\CapteurModel;
use App\Models\TenantModel;
use App\Models\UserModel;
use CodeIgniter\Database\Seeder;

/**
 * Seeder de base pour démarrer le projet avec un tenant, un compte admin et
 * quelques capteurs de test.
 */
class DefaultAppSeeder extends Seeder
{
    public function run()
    {
        $tenantModel = new TenantModel();
        $userModel   = new UserModel();
        $capteurModel = new CapteurModel();

        $tenant = $tenantModel->where('slug', 'obr')->first();

        if ($tenant === null) {
            $tenantId = $tenantModel->insert([
                'name'          => 'OBR',
                'slug'          => 'obr',
                'database_name' => null,
                'timezone'      => 'Africa/Bujumbura',
                'is_active'     => 1,
                'contact_email' => 'contact@obr.local',
                'logo_url'      => null,
                'notes'         => 'Tenant de démonstration pour les tests et le développement.',
            ], true);
        } else {
            $tenantId = (int) $tenant['id'];
        }

        $existingUser = $userModel->where('tenant_id', $tenantId)
            ->where('email', 'admin@obr.local')
            ->first();

        if ($existingUser === null) {
            $userModel->insert([
                'tenant_id'        => $tenantId,
                'email'           => 'admin@obr.local',
                'password_hash'   => password_hash('Admin123!', PASSWORD_DEFAULT),
                'nom_complet'     => 'Administrateur OBR',
                'telephone'       => '+250000000000',
                'role'            => 'admin',
                'is_super_admin'  => 1,
                'is_active'       => 1,
                'derniere_connexion' => null,
            ]);
        }

        $capteurs = [
            [
                'nom' => 'Entrée principale',
                'adresse_ip' => '10.100.28.230',
                'port' => 4370,
                'type_reseau' => 'LAN',
                'statut' => 'ACTIF',
            ],
            [
                'nom' => 'Bureau 1',
                'adresse_ip' => '10.100.28.231',
                'port' => 4370,
                'type_reseau' => 'LAN',
                'statut' => 'ACTIF',
            ],
            [
                'nom' => 'Siège distant',
                'adresse_ip' => '100.64.10.10',
                'port' => 4370,
                'type_reseau' => 'NETBIRD',
                'statut' => 'ACTIF',
            ],
        ];

        foreach ($capteurs as $capteur) {
            $exist = $capteurModel->where('tenant_id', $tenantId)
                ->where('nom', $capteur['nom'])
                ->first();

            if ($exist === null) {
                $capteurModel->insert([
                    'tenant_id' => $tenantId,
                    'nom' => $capteur['nom'],
                    'numero_serie' => null,
                    'adresse_ip' => $capteur['adresse_ip'],
                    'port' => $capteur['port'],
                    'type_reseau' => $capteur['type_reseau'],
                    'com_key' => null,
                    'firmware' => null,
                    'platform' => null,
                    'statut' => $capteur['statut'],
                    'dernier_scan' => null,
                    'derniere_sync_users' => null,
                    'derniere_sync_attendance' => null,
                    'timeout_secondes' => 60,
                    'derniere_erreur' => null,
                    'notes' => 'Capteur de test initialisé par le seeder.',
                ]);
            }
        }
    }
}
