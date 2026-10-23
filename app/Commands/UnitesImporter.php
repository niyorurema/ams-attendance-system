<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Importe une hiérarchie d’unités organisationnelles depuis un fichier CSV.
 *
 * Le fichier attendu est un CSV UTF-8 avec en-tête. Les colonnes reconnues sont:
 * code, nom, parent_code, type, niveau, adresse, telephone, email.
 */
class UnitesImporter extends BaseCommand
{
    protected $group = 'Unités';
    protected $name = 'unites:importer';
    protected $description = 'Importe la hiérarchie organisationnelle depuis un fichier CSV.';
    protected $usage = 'php spark unites:importer {fichier.csv} [--tenant=1]';
    protected $arguments = [
        'fichier.csv' => 'Chemin du fichier CSV à importer.',
    ];
    protected $options = [
        '--tenant' => 'Identifiant du tenant cible (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $filePath = $params[0] ?? null;
        if ($filePath === null || trim((string) $filePath) === '') {
            CLI::error('Chemin du fichier CSV requis. Usage : php spark unites:importer {fichier.csv} [--tenant=1]');

            return EXIT_ERROR;
        }

        if (! is_file($filePath)) {
            CLI::error(sprintf('Fichier introuvable : %s', $filePath));

            return EXIT_ERROR;
        }

        $tenantId = (int) (CLI::getOption('tenant') ?? 1);
        if ($tenantId < 1) {
            CLI::error('L’identifiant du tenant doit être supérieur à zéro.');

            return EXIT_ERROR;
        }

        $db = Database::connect();
        $summary = [
            'lignes' => 0,
            'creees' => 0,
            'maj' => 0,
            'ignores' => 0,
            'erreurs' => 0,
        ];

        try {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Impossible d’ouvrir le fichier CSV.');
            }

            $headers = fgetcsv($handle, 0, ',', '"');
            if ($headers === false || $headers === []) {
                throw new RuntimeException('Le fichier CSV est vide ou invalide.');
            }

            $mappedHeaders = $this->normaliserEnTetes($headers);
            $requiredColumns = ['code', 'nom', 'type', 'niveau'];
            foreach ($requiredColumns as $required) {
                if (! isset($mappedHeaders[$required])) {
                    throw new RuntimeException(sprintf('Colonne obligatoire absente : %s', $required));
                }
            }

            $indexParCode = [];
            $db->transBegin();

            while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
                $summary['lignes']++;
                if ($row === [null] || array_filter($row, static fn ($cell): bool => trim((string) $cell) !== '') === []) {
                    $summary['ignores']++;
                    continue;
                }

                $record = $this->normaliserLigne($row, $mappedHeaders);
                $code = trim((string) ($record['code'] ?? ''));
                $nom = trim((string) ($record['nom'] ?? ''));
                $type = strtoupper(trim((string) ($record['type'] ?? '')));
                $niveau = (int) ($record['niveau'] ?? 0);

                if ($code === '' || $nom === '') {
                    $summary['erreurs']++;
                    CLI::write(sprintf('Ligne %d ignorée : code ou nom vide.', $summary['lignes']), 'red');
                    continue;
                }

                $typesAutorises = ['COMMISSARIAT', 'DIRECTION', 'SERVICE', 'EQUIPE'];
                if (! in_array($type, $typesAutorises, true)) {
                    $summary['erreurs']++;
                    CLI::write(sprintf('Ligne %d ignorée : type invalide [%s].', $summary['lignes'], $type), 'red');
                    continue;
                }

                if ($niveau < 1 || $niveau > 20) {
                    $summary['erreurs']++;
                    CLI::write(sprintf('Ligne %d ignorée : niveau invalide [%d].', $summary['lignes'], $niveau), 'red');
                    continue;
                }

                $parentCode = trim((string) ($record['parent_code'] ?? ''));
                $parentId = null;
                if ($parentCode !== '') {
                    if (! isset($indexParCode[$parentCode])) {
                        $parent = $db->table('unites_organisationnelles')
                            ->where('tenant_id', $tenantId)
                            ->where('code', $parentCode)
                            ->where('deleted_at', null)
                            ->get()
                            ->getRowArray();

                        if ($parent === null) {
                            $summary['erreurs']++;
                            CLI::write(sprintf('Ligne %d ignorée : parent introuvable [%s].', $summary['lignes'], $parentCode), 'red');
                            continue;
                        }

                        $parentId = (int) $parent['id'];
                        $indexParCode[$parentCode] = $parentId;
                    } else {
                        $parentId = (int) $indexParCode[$parentCode];
                    }
                }

                $rowData = [
                    'tenant_id' => $tenantId,
                    'parent_id' => $parentId,
                    'nom' => $nom,
                    'code' => $code,
                    'type' => $type,
                    'niveau' => $niveau,
                    'adresse' => trim((string) ($record['adresse'] ?? '')) !== '' ? trim((string) ($record['adresse']) ) : null,
                    'telephone' => trim((string) ($record['telephone'] ?? '')) !== '' ? trim((string) ($record['telephone']) ) : null,
                    'email' => trim((string) ($record['email'] ?? '')) !== '' ? trim((string) ($record['email']) ) : null,
                    'ordre_affichage' => $summary['lignes'],
                    'actif' => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                $existing = $db->table('unites_organisationnelles')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->where('deleted_at', null)
                    ->get()
                    ->getRowArray();

                if ($existing !== null) {
                    $db->table('unites_organisationnelles')
                        ->where('id', (int) $existing['id'])
                        ->update($rowData);
                    $summary['maj']++;
                } else {
                    $rowData['created_at'] = date('Y-m-d H:i:s');
                    $db->table('unites_organisationnelles')->insert($rowData);
                    $summary['creees']++;
                }

                $indexParCode[$code] = (int) ($existing['id'] ?? $db->insertID());
            }

            $db->transCommit();
            fclose($handle);

            CLI::write('');
            CLI::write(sprintf('Import des unités terminé pour le tenant #%d.', $tenantId), 'green');
            CLI::write(sprintf('Lignes lues : %d', $summary['lignes']));
            CLI::write(sprintf('Créées : %d', $summary['creees']));
            CLI::write(sprintf('Mises à jour : %d', $summary['maj']));
            CLI::write(sprintf('Ignorées : %d', $summary['ignores']));
            CLI::write(sprintf('Erreurs : %d', $summary['erreurs']));

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            if ($db->transStatus() === false) {
                $db->transRollback();
            }

            CLI::error(sprintf('Échec de l’import CSV : %s', $e->getMessage()));

            return EXIT_ERROR;
        }
    }

    /**
     * Normalise les en-têtes CSV.
     *
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    protected function normaliserEnTetes(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $key = strtolower(trim((string) $header));
            $key = str_replace([' ', '-', '/'], '_', $key);
            $key = preg_replace('/_+/', '_', $key);
            if ($key === '') {
                continue;
            }
            $map[$key] = $index;
        }

        return $map;
    }

    /**
     * Normalise une ligne CSV en fonction des en-têtes reconnus.
     *
     * @param array<int, string|null> $row
     * @param array<string, int> $headerMap
     * @return array<string, string>
     */
    protected function normaliserLigne(array $row, array $headerMap): array
    {
        $normalized = [];
        foreach ($headerMap as $header => $index) {
            $normalized[$header] = $row[$index] ?? '';
        }

        if (! isset($normalized['parent_code']) && isset($normalized['parent'])) {
            $normalized['parent_code'] = $normalized['parent'];
        }

        return $normalized;
    }
}
