<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\Database;
use App\Services\ParametragePointageService;

/**
 * Initialise les valeurs configurables de pointage sans remplacer les valeurs existantes.
 */
class DefaultTenantAttendanceParameters extends Seeder
{
    public function run(): void
    {
        $db = Database::connect();
        $tenants = $db->table('tenants')
            ->select('id')
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $service = new ParametragePointageService($db);
        foreach ($tenants as $tenant) {
            $service->initialiserParametresParDefaut((int) $tenant['id']);
        }
    }
}
