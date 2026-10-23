<?php

namespace App\Commands;

use App\Services\HoraireService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Crée les horaires par défaut d’un tenant s’ils n’existent pas encore.
 */
class HorairesCreerDefaut extends BaseCommand
{
    protected $group = 'Horaires';
    protected $name = 'horaires:creer-defaut';
    protected $description = 'Crée les horaires par défaut des profils STANDARD, OBR et FLEXIBLE pour un tenant.';
    protected $usage = 'php spark horaires:creer-defaut {tenant_id}';
    protected $arguments = [
        'tenant_id' => 'Identifiant du tenant pour lequel créer les horaires par défaut.',
    ];

    public function run(array $params): int
    {
        $tenantId = $params[0] ?? null;
        if ($tenantId === null || filter_var($tenantId, FILTER_VALIDATE_INT) === false || (int) $tenantId < 1) {
            CLI::error('Identifiant de tenant invalide. Usage : php spark horaires:creer-defaut {tenant_id}');

            return EXIT_ERROR;
        }

        try {
            $service = new HoraireService();
            $types = ['STANDARD', 'OBR', 'FLEXIBLE'];
            $resultats = [];

            foreach ($types as $type) {
                $horaireId = $service->creerHoraireDefaut((int) $tenantId, $type);
                $resultats[$type] = $horaireId;
                CLI::write(sprintf('%s : %d', $type, $horaireId));
            }

            CLI::write(sprintf('Horaires par défaut créés pour le tenant #%d.', (int) $tenantId), 'green');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de la création des horaires par défaut : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
