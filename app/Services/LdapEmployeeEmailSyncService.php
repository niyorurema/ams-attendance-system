<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

final class LdapEmployeeEmailSyncService
{
    public function __construct(
        private ?BaseConnection $db = null,
        private ?LdapAuthService $ldap = null
    ) {
        $this->db ??= Database::connect();
        $this->ldap ??= new LdapAuthService($this->db);
    }

    /** @return array<string, int> */
    public function preview(int $tenantId): array
    {
        return $this->synchronize($tenantId, false);
    }

    /** @return array<string, int> */
    public function apply(int $tenantId): array
    {
        return $this->synchronize($tenantId, true);
    }

    /** @return array<string, int> */
    private function synchronize(int $tenantId, bool $apply): array
    {
        $employeeQuery = $this->db->table('employes')
            ->select('id, nom, prenom, matricule, email')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->groupStart()
                ->where('email', null)
                ->orWhere('email', '')
            ->groupEnd()
            ->get();
        if ($employeeQuery === false) {
            throw new RuntimeException('Impossible de charger les employés sans adresse email.');
        }

        $employees = $employeeQuery->getResultArray();
        $summary = [
            'employes_sans_email' => count($employees),
            'correspondances' => 0,
            'emails_employes_mis_a_jour' => 0,
            'comptes_sans_email' => 0,
            'emails_comptes_mis_a_jour' => 0,
            'ambigus' => 0,
            'introuvables' => 0,
            'emails_invalides' => 0,
            'collisions' => 0,
        ];
        if ($employees === []) {
            return $summary;
        }

        $matches = $this->ldap->rechercherEmailsEmployes($tenantId, array_map(
            static fn (array $employee): array => [
                'id' => (int) $employee['id'],
                'nom' => (string) ($employee['nom'] ?? ''),
                'prenom' => (string) ($employee['prenom'] ?? ''),
                'matricule' => (string) ($employee['matricule'] ?? ''),
            ],
            $employees
        ));
        $eligible = [];
        foreach ($employees as $employee) {
            $id = (int) $employee['id'];
            $match = $matches[$id] ?? ['statut' => 'introuvable', 'email' => null];
            if ($match['statut'] === 'ambigu') {
                $summary['ambigus']++;
            } elseif ($match['statut'] === 'email_invalide_ou_absent') {
                $summary['emails_invalides']++;
            } elseif ($match['statut'] === 'trouve'
                && is_string($match['email'] ?? null)
                && mb_strlen(trim($match['email'])) <= 150
            ) {
                $summary['correspondances']++;
                $eligible[$id] = mb_strtolower(trim($match['email']));
            } elseif ($match['statut'] === 'trouve') {
                $summary['emails_invalides']++;
            } else {
                $summary['introuvables']++;
            }
        }
        if ($eligible === []) {
            return $summary;
        }

        $duplicateEmailKeys = $this->findDuplicateValues($eligible);
        $candidateEmails = array_values(array_unique(array_values($eligible)));
        $existingEmployees = $this->getExistingEmails('employes', $tenantId, $candidateEmails);
        $employeeUpdates = [];
        foreach ($eligible as $employeeId => $email) {
            $key = mb_strtolower($email);
            if (isset($duplicateEmailKeys[$key]) || isset($existingEmployees[$key])) {
                $summary['collisions']++;
                continue;
            }
            $employeeUpdates[$employeeId] = $email;
        }

        if ($employeeUpdates === []) {
            return $summary;
        }

        $accountQuery = $this->db->table('users')
            ->select('id, employe_id, email')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->whereIn('employe_id', array_map('intval', array_keys($employeeUpdates)))
            ->groupStart()
                ->where('email', null)
                ->orWhere('email', '')
            ->groupEnd()
            ->get();
        if ($accountQuery === false) {
            throw new RuntimeException('Impossible de charger les comptes liés aux employés.');
        }
        $accounts = $accountQuery->getResultArray();
        $summary['comptes_sans_email'] = count($accounts);

        $accountUpdates = [];
        foreach ($accounts as $account) {
            $employeeId = (int) $account['employe_id'];
            if (isset($employeeUpdates[$employeeId])) {
                $accountUpdates[(int) $account['id']] = $employeeUpdates[$employeeId];
            }
        }

        $duplicateAccountEmails = $this->findDuplicateValues($accountUpdates);
        $existingAccounts = $this->getExistingEmails('users', $tenantId, array_values(array_unique(array_values($accountUpdates))));
        foreach ($accountUpdates as $accountId => $email) {
            $key = mb_strtolower($email);
            if (isset($duplicateAccountEmails[$key]) || isset($existingAccounts[$key])) {
                unset($accountUpdates[$accountId]);
                $summary['collisions']++;
            }
        }

        if (! $apply) {
            $summary['emails_employes_mis_a_jour'] = count($employeeUpdates);
            $summary['emails_comptes_mis_a_jour'] = count($accountUpdates);

            return $summary;
        }

        $db = $this->db;
        $db->transBegin();
        try {
            $employeeIds = array_map('intval', array_keys($employeeUpdates));
            $currentEmployeesQuery = $db->table('employes')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->whereIn('id', $employeeIds)
                ->groupStart()
                    ->where('email', null)
                    ->orWhere('email', '')
                ->groupEnd()
                ->get();
            if ($currentEmployeesQuery === false) {
                throw new RuntimeException('Impossible de vérifier les adresses des employés avant mise à jour.');
            }
            $currentEmployeeIds = array_map('intval', array_column($currentEmployeesQuery->getResultArray(), 'id'));
            if (count($currentEmployeeIds) !== count($employeeIds)) {
                throw new RuntimeException('Les données des employés ont changé pendant la synchronisation. Relancez une prévisualisation.');
            }

            $currentAccounts = [];
            if ($accountUpdates !== []) {
                $currentAccountsQuery = $db->table('users')
                    ->select('id')
                    ->where('tenant_id', $tenantId)
                    ->where('deleted_at', null)
                    ->whereIn('id', array_map('intval', array_keys($accountUpdates)))
                    ->groupStart()
                        ->where('email', null)
                        ->orWhere('email', '')
                    ->groupEnd()
                    ->get();
                if ($currentAccountsQuery === false) {
                    throw new RuntimeException('Impossible de vérifier les comptes avant mise à jour.');
                }
                $currentAccounts = array_map('intval', array_column($currentAccountsQuery->getResultArray(), 'id'));
                if (count($currentAccounts) !== count($accountUpdates)) {
                    throw new RuntimeException('Les données des comptes ont changé pendant la synchronisation. Relancez une prévisualisation.');
                }
            }

            foreach ($employeeUpdates as $employeeId => $email) {
                if (! $db->table('employes')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $employeeId)
                    ->groupStart()
                        ->where('email', null)
                        ->orWhere('email', '')
                    ->groupEnd()
                    ->update(['email' => $email])) {
                    throw new RuntimeException('Échec de mise à jour de l’adresse email d’un employé.');
                }
            }
            foreach ($accountUpdates as $accountId => $email) {
                if (! $db->table('users')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $accountId)
                    ->groupStart()
                        ->where('email', null)
                        ->orWhere('email', '')
                    ->groupEnd()
                    ->update(['email' => $email])) {
                    throw new RuntimeException('Échec de mise à jour de l’adresse email d’un compte lié.');
                }
            }
            if ($db->transStatus() === false) {
                throw new RuntimeException('La transaction de synchronisation des emails a échoué.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        $summary['emails_employes_mis_a_jour'] = count($employeeUpdates);
        $summary['emails_comptes_mis_a_jour'] = count($accountUpdates);

        return $summary;
    }

    /** @param array<int, string> $values
     *  @return array<string, true>
     */
    private function findDuplicateValues(array $values): array
    {
        $counts = [];
        foreach ($values as $value) {
            $key = mb_strtolower($value);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_fill_keys(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)), true);
    }

    /** @param list<string> $emails
     *  @return array<string, true>
     */
    private function getExistingEmails(string $table, int $tenantId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $query = $this->db->table($table)
            ->select('email')
            ->where('tenant_id', $tenantId)
            ->where('email !=', '')
            ->whereIn('email', $emails)
            ->get();
        if ($query === false) {
            throw new RuntimeException('Impossible de vérifier les conflits d’adresse email.');
        }

        $existing = [];
        foreach ($query->getResultArray() as $row) {
            $existing[mb_strtolower(trim((string) $row['email']))] = true;
        }

        return $existing;
    }
}
