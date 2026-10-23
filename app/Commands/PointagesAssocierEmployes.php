<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class PointagesAssocierEmployes extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:associer-employes';
    protected $description = 'Associe les pointages orphelins à leurs employés via les identifiants présents dans raw_data.';
    protected $usage = 'pointages:associer-employes [--tenant=1] [--dry-run] [--apply]';
    protected $options = [
        '--tenant' => 'ID du tenant à traiter (défaut : 1).',
        '--dry-run' => 'Simule les associations sans modifier la base (mode par défaut).',
        '--apply' => 'Applique les associations après vérification du dry-run.',
    ];

    public function run(array $params)
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');
            return EXIT_ERROR;
        }
        if (CLI::getOption('dry-run') !== null && CLI::getOption('apply') !== null) {
            CLI::error('Choisissez --dry-run ou --apply, pas les deux.');
            return EXIT_ERROR;
        }

        $dryRun = CLI::getOption('apply') === null;
        $db = Database::connect();
        $tenant = $db->table('tenants')->select('id')->where('id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
        if ($tenant === null) {
            CLI::error('Tenant introuvable.');
            return EXIT_ERROR;
        }

        CLI::write('=== Association des pointages orphelins ===', 'cyan');
        CLI::write('Tenant : ' . $tenantId);
        CLI::write('Mode : ' . ($dryRun ? 'DRY-RUN (aucune modification)' : 'APPLICATION'), $dryRun ? 'yellow' : 'red');

        $employes = $db->table('employes')
            ->select('id, matricule, badge_id, user_id_capteur')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $parMatricule = [];
        $parBadge = [];
        $parIdentifiantCapteur = [];
        foreach ($employes as $employe) {
            $this->indexerIdentifiant($parMatricule, $employe['matricule'] ?? null, (int) $employe['id']);
            $this->indexerIdentifiant($parBadge, $employe['badge_id'] ?? null, (int) $employe['id']);
            $this->indexerIdentifiant($parIdentifiantCapteur, $employe['user_id_capteur'] ?? null, (int) $employe['id']);
        }

        $total = $db->table('pointages')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', null)
            ->where('deleted_at', null)
            ->countAllResults();
        CLI::write('Employés actifs indexés : ' . count($employes));
        CLI::write('Pointages orphelins : ' . $total);

        $associes = 0;
        $ignores = 0;
        $dernierId = 0;
        $batchSize = 500;
        do {
            $pointages = $db->table('pointages')
                ->select('id, badge_id, raw_data')
                ->where('tenant_id', $tenantId)
                ->where('employe_id', null)
                ->where('deleted_at', null)
                ->where('id >', $dernierId)
                ->orderBy('id', 'ASC')
                ->limit($batchSize)
                ->get()
                ->getResultArray();

            foreach ($pointages as $pointage) {
                $dernierId = (int) $pointage['id'];
                $donnees = json_decode((string) ($pointage['raw_data'] ?? ''), true);
                $identifiants = is_array($donnees) ? $this->extraireIdentifiants($donnees) : [];
                $employeId = $this->trouverEmploye(
                    $identifiants,
                    (string) ($pointage['badge_id'] ?? ''),
                    $parMatricule,
                    $parBadge,
                    $parIdentifiantCapteur
                );

                if ($employeId === null) {
                    $ignores++;
                    continue;
                }

                if (! $dryRun) {
                    $db->table('pointages')
                        ->where('id', (int) $pointage['id'])
                        ->where('tenant_id', $tenantId)
                        ->where('employe_id', null)
                        ->update(['employe_id' => $employeId]);
                    if ($db->affectedRows() !== 1) {
                        CLI::error('Échec de mise à jour du pointage #' . $pointage['id'] . '.');
                        return EXIT_ERROR;
                    }
                }
                $associes++;
            }
        } while (count($pointages) === $batchSize);

        CLI::write('Associations possibles/appliquées : ' . $associes, 'green');
        CLI::write('Sans correspondance : ' . $ignores);
        if ($dryRun) {
            CLI::write('Simulation uniquement. Relancez avec --apply après avoir vérifié ce résultat.', 'yellow');
        }

        return EXIT_SUCCESS;
    }

    /** @param array<string, int|null> $index */
    private function indexerIdentifiant(array &$index, mixed $valeur, int $employeId): void
    {
        $identifiant = $this->normaliserIdentifiant($valeur);
        if ($identifiant === null) {
            return;
        }
        if (! array_key_exists($identifiant, $index)) {
            $index[$identifiant] = $employeId;
        } elseif ($index[$identifiant] !== $employeId) {
            $index[$identifiant] = null;
        }
    }

    /** @param array<mixed> $donnees
     *  @return array{matricules:array<int,string>,badges:array<int,string>,identifiants_capteur:array<int,string>}
     */
    private function extraireIdentifiants(array $donnees): array
    {
        $resultat = ['matricules' => [], 'badges' => [], 'identifiants_capteur' => []];
        $cles = [
            'matricules' => ['matricule', 'employee_id', 'employee_code', 'code_employe', 'user_id'],
            'badges' => ['badge_id', 'uid', 'pin'],
            'identifiants_capteur' => ['user_id_capteur'],
        ];
        $aParcourir = [$donnees];
        while ($aParcourir !== []) {
            $element = array_pop($aParcourir);
            foreach ($element as $cle => $valeur) {
                if (is_array($valeur)) {
                    $aParcourir[] = $valeur;
                    continue;
                }
                if (! is_scalar($valeur)) {
                    continue;
                }
                $cleNormalisee = strtolower((string) $cle);
                foreach ($cles as $categorie => $noms) {
                    if (in_array($cleNormalisee, $noms, true)) {
                        $identifiant = $this->normaliserIdentifiant($valeur);
                        if ($identifiant !== null) {
                            $resultat[$categorie][] = $identifiant;
                        }
                    }
                }
            }
        }

        return $resultat;
    }

    /** @param array{matricules:array<int,string>,badges:array<int,string>,identifiants_capteur:array<int,string>} $identifiants
     *  @param array<string, int|null> $parMatricule
     *  @param array<string, int|null> $parBadge
     *  @param array<string, int|null> $parIdentifiantCapteur
     */
    private function trouverEmploye(
        array $identifiants,
        string $badgePointage,
        array $parMatricule,
        array $parBadge,
        array $parIdentifiantCapteur
    ): ?int {
        foreach ($identifiants['matricules'] as $matricule) {
            if (isset($parMatricule[$matricule])) {
                return $parMatricule[$matricule];
            }
        }
        foreach ($identifiants['identifiants_capteur'] as $identifiant) {
            if (isset($parIdentifiantCapteur[$identifiant])) {
                return $parIdentifiantCapteur[$identifiant];
            }
        }
        foreach ([...$identifiants['badges'], $badgePointage] as $badge) {
            $badgeNormalise = $this->normaliserIdentifiant($badge);
            if ($badgeNormalise !== null && isset($parBadge[$badgeNormalise])) {
                return $parBadge[$badgeNormalise];
            }
        }

        return null;
    }

    private function normaliserIdentifiant(mixed $valeur): ?string
    {
        if (! is_string($valeur) && ! is_int($valeur)) {
            return null;
        }
        $identifiant = trim((string) $valeur);

        return $identifiant === '' ? null : $identifiant;
    }
}
