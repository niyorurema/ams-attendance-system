<?php

namespace App\Commands;

use App\Services\ImportGpioService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Résume les chefs GPIO non rapprochés à un employé AMS sans exposer leurs données.
 */
class GpioInvestiguerChefsManquants extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:investiguer-chefs-manquants';
    protected $description = 'Résume les mandats GPIO dont l’employé AMS est introuvable.';
    protected $usage = 'gpio:investiguer-chefs-manquants [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS cible (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');
            return EXIT_ERROR;
        }

        try {
            $result = (new ImportGpioService(null, Database::connect()))->importerChefs((int) $tenantId, true);
            $manquants = (int) ($result['raisons']['employe_ams_introuvable'] ?? 0);
            $sansMatricule = (int) ($result['raisons']['matricule_gpio_absent'] ?? 0);
            CLI::write('=== Chefs GPIO sans employé AMS ===', 'cyan');
            CLI::write('Mandats GPIO examinés : ' . $result['lues']);
            CLI::write('Employé AMS introuvable : ' . $manquants);
            CLI::write('Matricule GPIO absent : ' . $sansMatricule);
            CLI::write('Mandats ignorés pour d’autres motifs : ' . max(0, $result['ignores'] - $manquants - $sansMatricule));
            CLI::write('Aucun nom ni matricule individuel n’est affiché.', 'yellow');
            CLI::write('Actions : comparer les matricules et formats; confirmer si ces personnes doivent exister dans AMS avant toute création.');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Diagnostic des chefs GPIO impossible : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
