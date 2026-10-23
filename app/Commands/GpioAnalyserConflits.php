<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Affiche les services GPIO partageant un code avant leur import dans AMS.
 */
class GpioAnalyserConflits extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:analyser-conflits';
    protected $description = 'Analyse les codes de services GPIO avant import.';
    protected $usage = 'gpio:analyser-conflits [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS à vérifier (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $tenantOption = $this->optionValue('tenant');
        $tenantId = $tenantOption === null
            ? 1
            : (is_string($tenantOption) ? filter_var($tenantOption, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        try {
            $amsDb = Database::connect();
            if ($amsDb->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() !== 1) {
                throw new RuntimeException('Tenant AMS introuvable.');
            }

            $services = Database::connect('gpio', false)->table('etau_service')->get()->getResultArray();
            if ($services === []) {
                CLI::write('Aucun service GPIO trouvé.');

                return EXIT_SUCCESS;
            }

            $idColumn = $this->column($services, ['serviceID', 'service_id', 'id']);
            $typeColumn = $this->column($services, ['wich_is']);
            $statusColumn = $this->column($services, ['status']);
            $codeColumn = $this->column($services, ['code_service', 'service_code', 'code']);
            $nameColumn = $this->column($services, ['nom_service', 'service_name', 'nom', 'name']);
            $parentColumn = $this->column($services, ['parent_id', 'parent_service_id', 'id_parent']);
            if ($idColumn === null || $typeColumn === null || $statusColumn === null || $nameColumn === null) {
                throw new RuntimeException('Schéma GPIO etau_service incompatible avec l’analyse.');
            }

            $allById = [];
            $groupedByCode = [];
            $eligibleTypes = ['A', 'G', 'J', 'C', 'D', 'S', 'E'];
            foreach ($services as $service) {
                $id = trim((string) $service[$idColumn]);
                $allById[$id] = $service;
                if ((int) $service[$statusColumn] !== 1
                    || ! in_array(strtoupper(trim((string) $service[$typeColumn])), $eligibleTypes, true)
                ) {
                    continue;
                }

                $code = $codeColumn === null || trim((string) $service[$codeColumn]) === ''
                    ? 'SVC-' . $id
                    : trim((string) $service[$codeColumn]);
                $groupedByCode[mb_strtolower($code)]['code'] = $code;
                $groupedByCode[mb_strtolower($code)]['services'][] = $service;
            }

            $conflicts = array_filter(
                $groupedByCode,
                static fn (array $group): bool => count($group['services']) > 1
            );
            $amsUnits = $amsDb->table('unites_organisationnelles')
                ->select('id, code, source_id, source_systeme')
                ->where('tenant_id', $tenantId)
                ->get()
                ->getResultArray();
            $amsByCode = [];
            foreach ($amsUnits as $unit) {
                $amsByCode[mb_strtolower(trim((string) $unit['code']))][] = $unit;
            }

            CLI::write('=== Analyse des conflits de codes GPIO ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write('Base GPIO : lecture seule.');
            CLI::write('Lignes source lues : ' . count($services));

            if ($conflicts === []) {
                CLI::write('Aucun code source dupliqué parmi les services actifs importables.', 'green');
            } else {
                CLI::write('Groupes de codes source dupliqués : ' . count($conflicts), 'yellow');
                foreach ($conflicts as $group) {
                    CLI::write(PHP_EOL . sprintf('Code « %s » (%d services)', $group['code'], count($group['services'])), 'yellow');
                    foreach ($group['services'] as $service) {
                        $id = trim((string) $service[$idColumn]);
                        $parentId = $parentColumn === null ? '' : trim((string) $service[$parentColumn]);
                        $parent = $parentId !== '' && $parentId !== '0' ? ($allById[$parentId] ?? null) : null;
                        $parentCode = $parent === null || $codeColumn === null
                            ? ''
                            : trim((string) $parent[$codeColumn]);
                        $candidate = $group['code'];
                        if ($parentCode !== '') {
                            $candidate .= '-' . $parentCode;
                        }
                        CLI::write(sprintf(
                            '- serviceID=%s | nom=%s | parent_id=%s | base code (code + parent immédiat)=%s',
                            $id,
                            trim((string) $service[$nameColumn]),
                            $parentId === '' ? '(racine)' : $parentId,
                            $candidate
                        ));
                    }
                }
            }

            CLI::write(PHP_EOL . 'Codes source déjà présents dans AMS :');
            $foundAmsCollision = false;
            foreach ($groupedByCode as $key => $group) {
                if (! isset($amsByCode[$key])) {
                    continue;
                }
                $foundAmsCollision = true;
                foreach ($amsByCode[$key] as $unit) {
                    CLI::write(sprintf(
                        '- code=%s | unité AMS #%d | source=%s:%s',
                        $group['code'],
                        $unit['id'],
                        $unit['source_systeme'] ?? '(aucune)',
                        $unit['source_id'] ?? '(aucun)'
                    ));
                }
            }
            if (! $foundAmsCollision) {
                CLI::write('Aucun.');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de l’analyse GPIO : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
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
