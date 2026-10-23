<?php

namespace App\Commands;

use App\Services\LdapAuthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Cherche les emails AD manquants des chefs GPIO sans afficher de données personnelles.
 */
class GpioChercherEmailsAd extends BaseCommand
{
    protected $group = 'GPIO';
    protected $name = 'gpio:chercher-emails-ad';
    protected $description = 'Recherche par matricule et nom normalisé les emails AD manquants, en dry-run par défaut.';
    protected $usage = 'gpio:chercher-emails-ad [--tenant=1] [--dry-run|--apply]';
    protected $options = [
        '--tenant' => 'ID du tenant AMS cible (défaut : 1).',
        '--dry-run' => 'Simule sans écrire (mode par défaut).',
        '--apply' => 'Enregistre uniquement les emails trouvés sans ambiguïté.',
    ];

    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');
            return EXIT_ERROR;
        }

        $apply = CLI::getOption('apply') !== null;
        if ($apply && CLI::getOption('dry-run') !== null) {
            CLI::error('Choisissez --dry-run ou --apply, pas les deux.');
            return EXIT_ERROR;
        }

        $db = Database::connect();
        $transactionStarted = false;
        try {
            $employes = $db->table('users u')
                ->select('u.id, e.nom, e.prenom, e.matricule')
                ->join('employes e', 'e.id = u.employe_id')
                ->where('u.tenant_id', (int) $tenantId)
                ->where('u.email', null)
                ->where('u.employe_id IS NOT NULL', null, false)
                ->where('u.deleted_at', null)
                ->where('e.deleted_at', null)
                ->get()
                ->getResultArray();

            $matches = (new LdapAuthService($db))->rechercherEmailsEmployes(
                (int) $tenantId,
                array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'nom' => (string) ($row['nom'] ?? ''),
                    'prenom' => (string) ($row['prenom'] ?? ''),
                    'matricule' => (string) ($row['matricule'] ?? ''),
                ], $employes)
            );

            $stats = [
                'comptes_sans_email' => count($employes),
                'emails_trouves' => 0,
                'trouves_par_matricule' => 0,
                'trouves_par_nom' => 0,
                'noms_ambigus' => 0,
                'sans_correspondance' => 0,
                'email_absent_ou_invalide' => 0,
                'email_deja_utilise' => 0,
                'mis_a_jour' => 0,
                'erreurs' => 0,
            ];
            $candidats = [];
            $methodesParUtilisateur = [];
            foreach ($matches as $userId => $match) {
                if ($match['statut'] === 'trouve' && $match['email'] !== null) {
                    $candidats[$match['email']][] = (int) $userId;
                    $methodesParUtilisateur[(int) $userId] = $match['methode'];
                } elseif ($match['statut'] === 'ambigu') {
                    $stats['noms_ambigus']++;
                } elseif ($match['statut'] === 'introuvable') {
                    $stats['sans_correspondance']++;
                } else {
                    $stats['email_absent_ou_invalide']++;
                }
            }

            foreach ($candidats as $email => $userIds) {
                if (count($userIds) > 1) {
                    $stats['email_deja_utilise'] += count($userIds);
                    continue;
                }
                $userId = $userIds[0];
                $collision = $db->table('users')
                    ->select('id')
                    ->where('tenant_id', (int) $tenantId)
                    ->where('email', $email)
                    ->where('id !=', $userId)
                    ->where('deleted_at', null)
                    ->get()
                    ->getRowArray();
                if ($collision !== null) {
                    $stats['email_deja_utilise']++;
                    continue;
                }
                $stats['emails_trouves']++;
                if (($methodesParUtilisateur[$userId] ?? null) === 'matricule') {
                    $stats['trouves_par_matricule']++;
                } elseif (($methodesParUtilisateur[$userId] ?? null) === 'nom') {
                    $stats['trouves_par_nom']++;
                }
                $candidats[$email] = [$userId];
            }

            if ($apply) {
                $db->transBegin();
                $transactionStarted = true;
                foreach ($candidats as $email => $userIds) {
                    if (count($userIds) !== 1) {
                        continue;
                    }
                    $userId = $userIds[0];
                    $match = $matches[$userId] ?? null;
                    if (($match['statut'] ?? null) !== 'trouve' || $match['email'] !== $email) {
                        continue;
                    }
                    $updated = $db->table('users')
                        ->where('id', $userId)
                        ->where('tenant_id', (int) $tenantId)
                        ->where('email', null)
                        ->update(['email' => $email, 'auth_source' => 'LDAP', 'updated_at' => date('Y-m-d H:i:s')]);
                    if (! $updated) {
                        throw new RuntimeException('Mise à jour d’un compte AMS refusée; transaction annulée.');
                    }
                    $stats['mis_a_jour']++;
                }
                if ($db->transStatus() === false) {
                    throw new RuntimeException('Erreur de transaction AMS; modifications annulées.');
                }
                $db->transCommit();
                $transactionStarted = false;
            }

            $resumeComptes = $db->table('users')
                ->select("auth_source, CASE WHEN email IS NULL OR email = '' THEN 'SANS EMAIL' ELSE 'AVEC EMAIL' END AS statut_email, COUNT(*) AS total", false)
                ->where('tenant_id', (int) $tenantId)
                ->where('employe_id IS NOT NULL', null, false)
                ->where('deleted_at', null)
                ->groupBy('auth_source, statut_email')
                ->get()
                ->getResultArray();

            CLI::write('=== Recherche des emails AD des chefs GPIO ===', 'cyan');
            CLI::write('Tenant : ' . (int) $tenantId);
            CLI::write('Mode : ' . ($apply ? 'APPLICATION AMS' : 'DRY-RUN (aucune écriture)'), $apply ? 'red' : 'yellow');
            CLI::write('Comptes sans email examinés : ' . $stats['comptes_sans_email']);
            CLI::write('Emails uniques trouvés : ' . $stats['emails_trouves']);
            CLI::write('Correspondances par matricule exact : ' . $stats['trouves_par_matricule']);
            CLI::write('Correspondances par nom normalisé : ' . $stats['trouves_par_nom']);
            CLI::write('Noms ambigus : ' . $stats['noms_ambigus']);
            CLI::write('Aucune correspondance exploitable : ' . $stats['sans_correspondance']);
            CLI::write('Email absent/invalide : ' . $stats['email_absent_ou_invalide']);
            CLI::write('Emails déjà utilisés ou dupliqués : ' . $stats['email_deja_utilise']);
            if ($apply) {
                CLI::write('Comptes mis à jour : ' . $stats['mis_a_jour']);
            } else {
                CLI::write('Aucun email, nom ou identifiant individuel n’est affiché.', 'yellow');
            }
            CLI::write(PHP_EOL . 'Résumé des comptes liés à un employé :', 'cyan');
            foreach ($resumeComptes as $ligne) {
                CLI::write(sprintf(
                    '- %s / %s : %d',
                    $ligne['statut_email'],
                    $ligne['auth_source'],
                    (int) $ligne['total']
                ));
            }

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $db->transRollback();
            }
            CLI::error('Recherche des emails AD impossible : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
