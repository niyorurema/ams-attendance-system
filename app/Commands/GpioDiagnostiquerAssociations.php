<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\ApplicationIntegrationSettings;
use RuntimeException;
use Throwable;

/**
 * Diagnostique les clés de rapprochement GPIO/AMS sans afficher de données personnelles.
 */
class GpioDiagnostiquerAssociations extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:diagnostiquer-associations';
    protected $description = 'Mesure les correspondances GPIO/AMS pour les employés et chefs.';
    protected $usage = 'gpio:diagnostiquer-associations [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS à vérifier (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $tenantValue = $this->optionValue('tenant');
        $tenantId = $tenantValue === null
            ? 1
            : (is_string($tenantValue) ? filter_var($tenantValue, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        try {
            $ams = Database::connect();
            if ($ams->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() !== 1) {
                throw new RuntimeException('Tenant AMS introuvable.');
            }

            $gpio = (new ApplicationIntegrationSettings())->gpioConnection();
            $allUsers = $gpio->table('etau_utilisateur')->get()->getResultArray();
            $typeColumn = $this->column($allUsers, ['typeObjet', 'type_objet']);
            $idColumn = $this->column($allUsers, ['utilisateur_id', 'utilisateurID', 'userID', 'id_utilisateur', 'id']);
            $serviceColumn = $this->column($allUsers, ['serviceID', 'service_id', 'id_service']);
            $matriculeColumn = $this->column($allUsers, ['matricule', 'employee_id', 'numero_matricule']);
            if ($typeColumn === null || $idColumn === null || $serviceColumn === null) {
                throw new RuntimeException('Schéma GPIO etau_utilisateur incompatible : typeObjet, identifiant utilisateur et service sont requis.');
            }

            $users = array_values(array_filter(
                $allUsers,
                static fn (array $row): bool => strtoupper(trim((string) $row[$typeColumn])) === 'U'
            ));
            $employees = $ams->table('employes')
                ->select('id, source_id, matricule, user_id_capteur')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();
            $units = $ams->table('unites_organisationnelles')
                ->select('id, source_id')
                ->where('tenant_id', $tenantId)
                ->where('source_systeme', 'etau')
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();

            $sourceIds = [];
            $matricules = [];
            $sensorIds = [];
            foreach ($employees as $employee) {
                $this->addToIndex($sourceIds, $employee['source_id'] ?? null);
                $this->addToIndex($matricules, $employee['matricule'] ?? null);
                $this->addToIndex($sensorIds, $employee['user_id_capteur'] ?? null);
            }
            $unitIds = array_fill_keys(array_map(static fn (array $unit): string => (string) $unit['source_id'], $units), true);

            $withMatricule = 0;
            $sourceIdMatches = 0;
            $matriculeMatches = 0;
            $sensorIdMatches = 0;
            $unitMatches = 0;
            $ambiguousMatches = 0;
            foreach ($users as $user) {
                $userId = trim((string) $user[$idColumn]);
                $matricule = $matriculeColumn === null ? '' : trim((string) $user[$matriculeColumn]);
                $serviceId = trim((string) $user[$serviceColumn]);
                if ($matricule !== '') {
                    $withMatricule++;
                    if (isset($matricules[$matricule])) {
                        $matriculeMatches++;
                    }
                }
                if (isset($sourceIds[$userId])) {
                    $sourceIdMatches++;
                }
                if (isset($sensorIds[$userId])) {
                    $sensorIdMatches++;
                }
                if (isset($unitIds[$serviceId])) {
                    $unitMatches++;
                }
                $matched = (array_key_exists($userId, $sourceIds) && $sourceIds[$userId] === null)
                    || ($matricule !== '' && array_key_exists($matricule, $matricules) && $matricules[$matricule] === null)
                    || (array_key_exists($userId, $sensorIds) && $sensorIds[$userId] === null);
                if ($matched) {
                    $ambiguousMatches++;
                }
            }

            $usersBySourceId = [];
            foreach ($users as $user) {
                $usersBySourceId[trim((string) $user[$idColumn])] = $user;
            }
            $chefRows = $gpio->table('etau_service_chef')->get()->getResultArray();
            $chefStatusColumn = $this->column($chefRows, ['estactif', 'actif', 'status']);
            $chefUserColumn = $this->column($chefRows, ['utilisateur_id', 'utilisateurID', 'userID', 'user_id', 'id_utilisateur']);
            $chefServiceColumn = $this->column($chefRows, ['serviceID', 'service_id', 'id_service']);
            if ($chefUserColumn === null || $chefServiceColumn === null) {
                throw new RuntimeException('Schéma GPIO etau_service_chef incompatible : identifiants service et utilisateur requis.');
            }
            $activeChefs = 0;
            $activeChefUserMatches = 0;
            $activeChefEmployeeMatches = 0;
            $activeChefUnitMatches = 0;
            $activeChefAccounts = 0;
            foreach ($chefRows as $chef) {
                if ($chefStatusColumn !== null && (int) $chef[$chefStatusColumn] !== 1) {
                    continue;
                }
                $activeChefs++;
                $userId = trim((string) $chef[$chefUserColumn]);
                $serviceId = trim((string) $chef[$chefServiceColumn]);
                $gpioUser = $usersBySourceId[$userId] ?? null;
                if ($gpioUser !== null) {
                    $activeChefUserMatches++;
                    $gpioMatricule = $matriculeColumn === null ? '' : trim((string) $gpioUser[$matriculeColumn]);
                    $employee = $this->hasIndexMatch($sourceIds, $userId)
                        || ($gpioMatricule !== '' && $this->hasIndexMatch($matricules, $gpioMatricule))
                        || $this->hasIndexMatch($sensorIds, $userId);
                    if ($employee) {
                        $activeChefEmployeeMatches++;
                        $employeeId = $this->employeeIdBySourceOrMatricule($employees, $userId, $gpioMatricule);
                        if ($employeeId !== null && $ams->table('users')->where('tenant_id', $tenantId)->where('employe_id', $employeeId)->where('deleted_at', null)->countAllResults() > 0) {
                            $activeChefAccounts++;
                        }
                    }
                }
                if (isset($unitIds[$serviceId])) {
                    $activeChefUnitMatches++;
                }
            }

            CLI::write('=== Diagnostic des associations GPIO / AMS ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId . ' | GPIO en lecture seule.');
            CLI::write(sprintf('etau_utilisateur : %d lignes au total; %d de typeObjet=U.', count($allUsers), count($users)));
            CLI::write(sprintf('Employés GPIO type U avec matricule : %d.', $withMatricule));
            CLI::write(sprintf(
                'Correspondances employés AMS : source_id=%d, matricule=%d, user_id_capteur=%d; ambiguïtés de clé=%d.',
                $sourceIdMatches,
                $matriculeMatches,
                $sensorIdMatches,
                $ambiguousMatches
            ));
            CLI::write(sprintf('Utilisateurs GPIO dont serviceID correspond à une unité importée : %d.', $unitMatches));
            CLI::write(sprintf(
                'Chefs actifs : %d; utilisateur GPIO trouvé=%d; employé AMS associé=%d; unité trouvée=%d; compte AMS lié=%d.',
                $activeChefs,
                $activeChefUserMatches,
                $activeChefEmployeeMatches,
                $activeChefUnitMatches,
                $activeChefAccounts
            ));
            CLI::write('Aucun nom, matricule, courriel ou identifiant individuel n’est affiché.');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec du diagnostic GPIO : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param list<string> $candidates
     */
    private function column(array $rows, array $candidates): ?string
    {
        if ($rows === []) {
            return null;
        }
        $available = [];
        foreach (array_keys($rows[0]) as $name) {
            $available[strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name))] = (string) $name;
        }
        foreach ($candidates as $candidate) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $candidate));
            if (isset($available[$key])) {
                return $available[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string,int|null> $index
     */
    private function addToIndex(array &$index, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        $key = trim((string) $value);
        $index[$key] = array_key_exists($key, $index) ? null : 1;
    }

    /**
     * @param array<string,int|null> $index
     */
    private function hasIndexMatch(array $index, string $key): bool
    {
        return isset($index[trim($key)]);
    }

    /**
     * @param list<array<string,mixed>> $employees
     */
    private function employeeIdBySourceOrMatricule(array $employees, string $sourceId, string $matricule): ?int
    {
        foreach ($employees as $employee) {
            if (($employee['source_id'] !== null && (string) $employee['source_id'] === $sourceId)
                || ($matricule !== '' && (string) $employee['matricule'] === $matricule)
            ) {
                return (int) $employee['id'];
            }
        }

        return null;
    }

    private function optionValue(string $name): string|bool|null
    {
        $options = CLI::getOptions();
        if (array_key_exists($name, $options)) {
            return $options[$name] ?? true;
        }
        foreach ($options as $option => $value) {
            if (str_starts_with($option, $name . '=')) {
                return substr($option, strlen($name) + 1);
            }
        }

        return null;
    }
}
