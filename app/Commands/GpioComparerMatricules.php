<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Compare les populations de matricules GPIO et AMS sans révéler leurs valeurs.
 */
class GpioComparerMatricules extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:comparer-matricules';
    protected $description = 'Compare les matricules GPIO type U et AMS de façon agrégée.';
    protected $usage = 'gpio:comparer-matricules [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS à comparer (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $options = CLI::getOptions();
        $tenantValue = $options['tenant'] ?? null;
        if ($tenantValue === null) {
            foreach ($options as $option => $value) {
                if (str_starts_with($option, 'tenant=')) {
                    $tenantValue = substr($option, strlen('tenant='));
                    break;
                }
            }
        }
        $tenantId = $tenantValue === null
            ? 1
            : (is_string($tenantValue) ? filter_var($tenantValue, FILTER_VALIDATE_INT) : false);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');

            return EXIT_ERROR;
        }

        try {
            $amsDb = Database::connect();
            if ($amsDb->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() !== 1) {
                throw new RuntimeException('Tenant AMS introuvable.');
            }

            $gpioRows = Database::connect('gpio', false)->table('etau_utilisateur')->get()->getResultArray();
            if ($gpioRows === []) {
                CLI::write('Aucune ligne GPIO trouvée.');

                return EXIT_SUCCESS;
            }

            $typeColumn = $this->column($gpioRows, ['typeObjet', 'type_objet']);
            $matriculeColumn = $this->column($gpioRows, ['matricule', 'employee_id', 'numero_matricule']);
            if ($typeColumn === null || $matriculeColumn === null) {
                throw new RuntimeException('Schéma GPIO incompatible : typeObjet et matricule sont requis.');
            }
            $gpioUsers = array_values(array_filter(
                $gpioRows,
                static fn (array $row): bool => strtoupper(trim((string) $row[$typeColumn])) === 'U'
            ));
            $amsRows = $amsDb->table('employes')
                ->select('matricule')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getResultArray();

            $gpioCounts = $this->countValues(array_map(
                static fn (array $row): string => (string) $row[$matriculeColumn],
                $gpioUsers
            ));
            $amsCounts = $this->countValues(array_map(
                static fn (array $row): string => (string) $row['matricule'],
                $amsRows
            ));
            $gpioKeys = array_fill_keys(array_keys($gpioCounts), true);
            $amsKeys = array_fill_keys(array_keys($amsCounts), true);
            $common = array_intersect_key($gpioKeys, $amsKeys);
            $gpioOnly = array_diff_key($gpioKeys, $amsKeys);
            $amsOnly = array_diff_key($amsKeys, $gpioKeys);

            CLI::write('=== Comparaison agrégée des matricules ===', 'cyan');
            CLI::write(sprintf(
                'GPIO typeObjet=U : %d lignes, %d matricules distincts, %d matricules vides.',
                count($gpioUsers),
                count($gpioKeys),
                count(array_filter($gpioUsers, static fn (array $row): bool => trim((string) $row[$matriculeColumn]) === ''))
            ));
            CLI::write(sprintf(
                'AMS tenant %d : %d employés, %d matricules distincts.',
                $tenantId,
                count($amsRows),
                count($amsKeys)
            ));
            CLI::write(sprintf(
                'Matricules communs : %d | GPIO uniquement : %d | AMS uniquement : %d.',
                count($common),
                count($gpioOnly),
                count($amsOnly)
            ));
            CLI::write('Répartition des longueurs (matricules non vides) :');
            CLI::write('GPIO : ' . $this->formatLengthDistribution($gpioCounts));
            CLI::write('AMS  : ' . $this->formatLengthDistribution($amsCounts));
            CLI::write('Aucune valeur de matricule ni donnée nominative n’est affichée.');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Échec de la comparaison des matricules : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param list<string> $candidates
     */
    private function column(array $rows, array $candidates): ?string
    {
        $available = [];
        foreach (array_keys($rows[0] ?? []) as $name) {
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
     * @param list<string> $values
     * @return array<string,int>
     */
    private function countValues(array $values): array
    {
        $counts = [];
        foreach ($values as $value) {
            $normalized = trim($value);
            if ($normalized === '') {
                continue;
            }
            $key = mb_strtolower($normalized);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string,int> $values
     */
    private function formatLengthDistribution(array $values): string
    {
        $distribution = [];
        foreach (array_keys($values) as $value) {
            $length = mb_strlen($value);
            $distribution[$length] = ($distribution[$length] ?? 0) + 1;
        }
        ksort($distribution);

        $parts = [];
        foreach ($distribution as $length => $count) {
            $parts[] = sprintf('%d caractères : %d', $length, $count);
        }

        return $parts === [] ? '(aucun)' : implode('; ', $parts);
    }
}
