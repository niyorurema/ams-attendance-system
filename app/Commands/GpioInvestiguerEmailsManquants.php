<?php

namespace App\Commands;

use App\Services\LdapAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Affiche les détails des comptes chefs qui restent sans email après la recherche AD.
 */
class GpioInvestiguerEmailsManquants extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:investiguer-emails-manquants';
    protected $description = 'Détaille les comptes chefs restés sans email, sans afficher les emails AD.';
    protected $usage = 'gpio:investiguer-emails-manquants [--tenant=1]';
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
            $db = Database::connect();
            $comptes = $db->table('users u')
                ->select('u.id, e.matricule, e.nom, e.prenom, GROUP_CONCAT(DISTINCT o.nom ORDER BY o.nom SEPARATOR ", ") AS unites', false)
                ->join('employes e', 'e.id = u.employe_id')
                ->join('unit_chefs uc', 'uc.user_id = u.id AND uc.tenant_id = u.tenant_id AND uc.deleted_at IS NULL', 'left')
                ->join('unites_organisationnelles o', 'o.id = uc.unite_id AND o.tenant_id = u.tenant_id AND o.deleted_at IS NULL', 'left')
                ->where('u.tenant_id', (int) $tenantId)
                ->where('u.email', null)
                ->where('u.employe_id IS NOT NULL', null, false)
                ->where('u.deleted_at', null)
                ->where('e.deleted_at', null)
                ->groupBy('u.id, e.matricule, e.nom, e.prenom')
                ->get()
                ->getResultArray();

            if ($comptes === []) {
                CLI::write('Aucun compte de chef lié à un employé ne reste sans email.');
                return EXIT_SUCCESS;
            }

            $matches = (new LdapAuthService($db))->rechercherEmailsEmployes(
                (int) $tenantId,
                array_map(static fn (array $compte): array => [
                    'id' => (int) $compte['id'],
                    'matricule' => (string) ($compte['matricule'] ?? ''),
                    'nom' => (string) ($compte['nom'] ?? ''),
                    'prenom' => (string) ($compte['prenom'] ?? ''),
                ], $comptes)
            );

            $parStatut = ['ambigu' => [], 'introuvable' => [], 'autre' => []];
            foreach ($comptes as $compte) {
                $id = (int) $compte['id'];
                $statut = $matches[$id]['statut'] ?? 'autre';
                $groupe = match ($statut) {
                    'ambigu' => 'ambigu',
                    'introuvable' => 'introuvable',
                    default => 'autre',
                };
                $parStatut[$groupe][] = $compte;
            }

            CLI::write('=== Comptes chefs encore sans email ===', 'cyan');
            CLI::write('Comptes examinés : ' . count($comptes));
            foreach ([
                'ambigu' => 'Correspondance AD ambiguë',
                'introuvable' => 'Aucune correspondance AD par nom/matricule',
                'autre' => 'Correspondance unique sans email utilisable',
            ] as $groupe => $titre) {
                if ($parStatut[$groupe] === []) {
                    continue;
                }
                CLI::write(PHP_EOL . $titre . ' (' . count($parStatut[$groupe]) . ')', 'yellow');
                foreach ($parStatut[$groupe] as $compte) {
                    $nomComplet = trim((string) $compte['nom'] . ' ' . (string) $compte['prenom']);
                    CLI::write(sprintf(
                        '- Matricule %s | %s | %s',
                        (string) ($compte['matricule'] ?? 'absent'),
                        $nomComplet,
                        (string) ($compte['unites'] ?? 'unité non liée')
                    ));
                }
            }
            CLI::write(PHP_EOL . 'Diagnostic en lecture seule; aucun email AD ou mot de passe n’est affiché.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Investigation des emails manquants impossible : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
