<?php

namespace App\Commands;

use App\Models\CapteurModel;
use App\Models\EmployeModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Vérifie les prérequis en base avant un test du push ADMS.
 */
class AdmsCheckReady extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'adms:check-ready';
    protected $description = 'Vérifie les prérequis en base pour recevoir les pushs ADMS.';
    protected $usage = 'adms:check-ready [capteur_id]';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur à vérifier. Valeur par défaut : 1.',
    ];

    public function run(array $params): int
    {
        $capteurId = $params[0] ?? 1;
        if (filter_var($capteurId, FILTER_VALIDATE_INT) === false || (int) $capteurId < 1) {
            CLI::error('Identifiant de capteur invalide. Usage : php spark adms:check-ready [capteur_id]');

            return EXIT_ERROR;
        }

        $capteurId = (int) $capteurId;
        CLI::write('');
        CLI::write(sprintf('=== Vérification ADMS en base : capteur #%d ===', $capteurId), 'green');

        try {
            $capteur = (new CapteurModel())->find($capteurId);
            if (! is_array($capteur)) {
                CLI::error(sprintf('ÉCHEC : capteur #%d introuvable ou supprimé.', $capteurId));

                return EXIT_ERROR;
            }

            CLI::write(sprintf('Capteur : %s (tenant #%d)', $capteur['nom'], $capteur['tenant_id']));

            $ready = true;
            $serialNumber = trim((string) ($capteur['numero_serie'] ?? ''));
            if ($serialNumber === '') {
                CLI::error('ÉCHEC : numero_serie est NULL ou vide; les requêtes ADMS ne pourront pas identifier ce capteur.');
                $ready = false;
            } else {
                CLI::write(sprintf('OK : numero_serie = %s', $serialNumber), 'green');
            }

            if ($capteur['statut'] !== 'ACTIF') {
                CLI::error(sprintf('ÉCHEC : statut du capteur = %s (attendu : ACTIF).', $capteur['statut']));
                $ready = false;
            } else {
                CLI::write('OK : capteur actif.', 'green');
            }

            $employeeCount = (new EmployeModel())
                ->where('tenant_id', (int) $capteur['tenant_id'])
                ->countAllResults();

            if ($employeeCount === 0) {
                CLI::error(sprintf('ÉCHEC : aucun employé trouvé dans le tenant #%d.', $capteur['tenant_id']));
                $ready = false;
            } else {
                CLI::write(sprintf(
                    'OK : %d employés présents dans le tenant #%d.',
                    $employeeCount,
                    $capteur['tenant_id']
                ), 'green');
            }

            CLI::write('');
            if (! $ready) {
                CLI::error('Prérequis base ADMS : INCOMPLETS');

                return EXIT_ERROR;
            }

            CLI::write('Prérequis base ADMS : OK', 'green');
            CLI::write('Cette commande ne vérifie pas le réseau, le pare-feu ni la réception effective des requêtes HTTP.');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Erreur pendant la vérification en base : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}