<?php

namespace App\Commands;

use App\Services\RapportService;
use App\Services\SystemJobService;
use App\Services\SystemJobRetryProcessor;
use App\Services\ZKTecoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

/** Exécute un nombre limité de relances approuvées depuis l’interface admin. */
final class SystemJobsProcessRetries extends BaseCommand
{
    protected $group = 'System';
    protected $name = 'system:jobs:process-retries';
    protected $description = 'Traite les relances approuvées en file d’attente.';

    public function run(array $params): int
    {
        $jobs = new SystemJobService();
        $result = (new SystemJobRetryProcessor($jobs))->process(function (array $request): array {
            $parameters = is_array($request['parameters'] ?? null) ? $request['parameters'] : [];
            return match ((string) $request['task_key']) {
                'rapports:envoyer-quotidiens' => $this->relancerRapports($parameters),
                'gpio:sync-obr' => $this->executerSynchronisationGpio(),
                'capteurs:sync-attendance' => $this->synchroniserCapteur((int) $request['scope_id']),
                default => throw new RuntimeException('Tâche en file non autorisée.'),
            };
        });

        CLI::write(sprintf('Relances traitées : %d; erreurs : %d.', $result['processed'], $result['errors']));

        return $result['errors'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /** @return array<string, int> */
    private function relancerRapports(array $parameters): array
    {
        $context = $parameters['report_context'] ?? null;
        if (! is_array($context) || $context === []) {
            throw new RuntimeException('Contexte original des rapports absent ou invalide.');
        }

        return (new RapportService())->envoyerRapportsQuotidiens($context);
    }

    /** @return array<string, mixed> */
    private function executerSynchronisationGpio(): array
    {
        $exitCode = (new GpioSyncObr())->run([]);
        if ($exitCode !== EXIT_SUCCESS) {
            throw new RuntimeException('La synchronisation ETau a échoué; consultez les journaux serveur.');
        }

        return ['commande' => 'gpio:sync-obr', 'resultat' => 'terminée'];
    }

    /** @return array<string, mixed> */
    private function synchroniserCapteur(int $sensorId): array
    {
        if ($sensorId < 1) {
            throw new RuntimeException('Identifiant de capteur invalide.');
        }

        return (new ZKTecoService())->syncPointages($sensorId);
    }
}
