<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;

/**
 * Importe en lecture seule depuis GPIO et synchronise les référentiels AMS.
 */
final class ImportGpioService
{
    private const SOURCE_SYSTEME = 'etau';
    private const TYPES_AUTORISES = ['A', 'G', 'J', 'C', 'D', 'S', 'E'];

    private BaseConnection $gpioDb;
    private BaseConnection $amsDb;

    public function __construct(?BaseConnection $gpioDb = null, ?BaseConnection $amsDb = null)
    {
        $this->gpioDb = $gpioDb ?? Database::connect('etau', false);
        $this->amsDb = $amsDb ?? Database::connect();
    }

    /**
     * Importe la hiérarchie organisationnelle sans jamais écrire dans GPIO.
     *
     * @return array{lues:int,crees:int,maj:int,ignores:int,erreurs:int,raisons:array<string,int>,details:list<string>}
     */
    public function importerHierarchie(int $tenantId, bool $dryRun = true): array
    {
        $this->verifierTenant($tenantId);
        $services = $this->gpioDb->table('etau_service')->get()->getResultArray();
        $stats = $this->nouvellesStats(count($services));
        $selection = [];
        $statutsExclus = [];
        $typesExclus = [];
        $idColumn = $this->colonne($services, ['serviceID', 'service_id', 'id']);
        $typeColumn = $this->colonne($services, ['wich_is']);
        $statusColumn = $this->colonne($services, ['status']);
        if ($idColumn === null || $typeColumn === null || $statusColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_service incompatible : serviceID, wich_is et status sont requis.');
        }

        foreach ($services as $service) {
            if ((int) $service[$statusColumn] !== 1) {
                $stats['ignores']++;
                $status = trim((string) $service[$statusColumn]);
                $statutsExclus[$status === '' ? '(vide)' : $status] = ($statutsExclus[$status === '' ? '(vide)' : $status] ?? 0) + 1;
                continue;
            }
            $type = strtoupper(trim((string) $service[$typeColumn]));
            if (! in_array($type, self::TYPES_AUTORISES, true)) {
                $stats['ignores']++;
                $typesExclus[$type === '' ? '(NULL/vide)' : $type] = ($typesExclus[$type === '' ? '(NULL/vide)' : $type] ?? 0) + 1;
                continue;
            }
            $sourceId = trim((string) $service[$idColumn]);
            if (! ctype_digit($sourceId) || (int) $sourceId < 1) {
                $stats['erreurs']++;
                $stats['details'][] = 'Service GPIO ignoré : identifiant source invalide.';
                continue;
            }
            $sourceId = (string) (int) $sourceId;
            if (isset($selection[$sourceId])) {
                $stats['erreurs']++;
                $stats['details'][] = sprintf('Identifiant source GPIO dupliqué : %s.', $sourceId);
                continue;
            }
            $selection[$sourceId] = $service;
        }

        $groupesCodeParent = [];
        foreach ($selection as $sourceId => $service) {
            $parentColumn = $this->colonne([$service], ['parent_id', 'parent_service_id', 'id_parent']);
            $parentSourceId = $parentColumn === null ? '' : trim((string) $service[$parentColumn]);
            $codeColumn = $this->colonne([$service], ['code_service', 'service_code', 'code']);
            $code = $codeColumn === null || trim((string) $service[$codeColumn]) === ''
                ? 'SVC-' . $sourceId
                : trim((string) $service[$codeColumn]);
            $signature = $parentSourceId . '|' . mb_strtolower($code);
            $groupesCodeParent[$signature][] = [$sourceId, $service, $code];
        }
        $qualificatifsParSource = [];
        foreach ($groupesCodeParent as $groupe) {
            if (count($groupe) < 2) {
                continue;
            }
            foreach ($groupe as [$sourceId, $service]) {
                $nameColumn = $this->colonne([$service], ['nom_service', 'service_name', 'nom', 'name']);
                $name = $nameColumn === null ? '' : (string) $service[$nameColumn];
                $qualificatif = $this->acronymeDistinctif($name);
                if ($qualificatif === '') {
                    $stats['erreurs']++;
                    $stats['details'][] = sprintf('Service source %s : impossible de produire un sigle distinctif à partir du nom.', $sourceId);
                    continue;
                }
                $qualificatifsParSource[$sourceId] = $qualificatif;
            }
        }

        if ($statutsExclus !== []) {
            $stats['details'][] = 'Exclus par status : ' . $this->formaterRepartition($statutsExclus) . '.';
        }
        if ($typesExclus !== []) {
            $stats['details'][] = 'Exclus par wich_is non pris en charge : ' . $this->formaterRepartition($typesExclus) . '.';
        }

        $unitesExistantes = $this->amsDb->table('unites_organisationnelles')
            ->where('tenant_id', $tenantId)
            ->where('source_systeme', self::SOURCE_SYSTEME)
            ->get()
            ->getResultArray();
        $toutesUnites = $this->amsDb->table('unites_organisationnelles')
            ->select('id, code')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getResultArray();
        $proprietairesCodes = [];
        foreach ($toutesUnites as $unite) {
            $proprietairesCodes[mb_strtolower(trim((string) $unite['code']))] = (int) $unite['id'];
        }
        $mapping = [];
        $codesParSource = [];
        foreach ($unitesExistantes as $unite) {
            $mapping[(string) $unite['source_id']] = (int) $unite['id'];
            $codesParSource[(string) $unite['source_id']] = trim((string) $unite['code']);
        }

        $pending = $selection;
        $codesPlanifies = [];
        $ordre = 0;
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $sourceId => $service) {
                $parentColumn = $this->colonne([$service], ['parent_id', 'parent_service_id', 'id_parent']);
                $parentSourceId = $parentColumn === null ? null : trim((string) $service[$parentColumn]);
                $parentId = null;
                if ($parentSourceId !== null && $parentSourceId !== '' && $parentSourceId !== '0') {
                    if (isset($selection[$parentSourceId]) && isset($pending[$parentSourceId])) {
                        continue;
                    }
                    if (! isset($mapping[$parentSourceId])) {
                        if (isset($selection[$parentSourceId])) {
                            continue;
                        }
                        $stats['erreurs']++;
                        $stats['details'][] = sprintf('Service source %s ignoré : parent %s non importable ou introuvable.', $sourceId, $parentSourceId);
                        unset($pending[$sourceId]);
                        $progress = true;
                        continue;
                    }
                    $parentId = $mapping[$parentSourceId];
                }

                $nameColumn = $this->colonne([$service], ['nom_service', 'service_name', 'nom', 'name']);
                if ($nameColumn === null || trim((string) $service[$nameColumn]) === '') {
                    $stats['erreurs']++;
                    $stats['details'][] = sprintf('Service source %s ignoré : nom absent.', $sourceId);
                    unset($pending[$sourceId]);
                    $progress = true;
                    continue;
                }
                $codeColumn = $this->colonne([$service], ['code_service', 'service_code', 'code']);
                $codeOriginal = $codeColumn !== null && trim((string) $service[$codeColumn]) !== ''
                    ? trim((string) $service[$codeColumn])
                    : 'SVC-' . $sourceId;
                $code = $codeOriginal;
                if ($parentSourceId !== null && $parentSourceId !== '' && $parentSourceId !== '0') {
                    $parentService = $selection[$parentSourceId] ?? null;
                    $parentCodeColumn = $parentService === null ? null : $this->colonne([$parentService], ['code_service', 'service_code', 'code']);
                    $parentCode = $codesParSource[$parentSourceId] ?? '';
                    if ($parentCode === '' && $parentService !== null && $parentCodeColumn !== null) {
                        $parentCode = trim((string) $parentService[$parentCodeColumn]);
                    }
                    if ($parentCode === '') {
                        $parentCode = 'SVC-' . $parentSourceId;
                    }
                    $parentCodeFinal = $codesParSource[$parentSourceId] ?? $parentCode;
                    if (isset($qualificatifsParSource[$sourceId])) {
                        $code .= '-' . $qualificatifsParSource[$sourceId];
                    }
                    $code .= '-' . $parentCodeFinal;
                    $stats['details'][] = sprintf(
                        'Code source %s composé : %s → %s (code du parent %s).',
                        $sourceId,
                        $codeOriginal,
                        $code,
                        $parentCodeFinal
                    );
                } elseif (isset($qualificatifsParSource[$sourceId])) {
                    $code .= '-' . $qualificatifsParSource[$sourceId];
                    $stats['details'][] = sprintf(
                        'Code source %s composé : %s → %s (sigle distinctif du nom).',
                        $sourceId,
                        $codeOriginal,
                        $code
                    );
                }
                if (mb_strlen($code) > 50) {
                    $stats['erreurs']++;
                    $stats['details'][] = sprintf('Service source %s ignoré : code composé trop long (%d caractères, maximum 50).', $sourceId, mb_strlen($code));
                    unset($pending[$sourceId]);
                    $progress = true;
                    continue;
                }
                $codeKey = mb_strtolower($code);
                $existing = null;
                foreach ($unitesExistantes as $unite) {
                    if ((int) $unite['source_id'] === (int) $sourceId
                        && (string) $unite['source_systeme'] === self::SOURCE_SYSTEME
                    ) {
                        $existing = $unite;
                        break;
                    }
                }
                if ((isset($proprietairesCodes[$codeKey])
                        && ($existing === null || $proprietairesCodes[$codeKey] !== (int) $existing['id']))
                    || (isset($codesPlanifies[$codeKey]) && $codesPlanifies[$codeKey] !== $sourceId)
                ) {
                    $stats['erreurs']++;
                    $stats['details'][] = sprintf('Service source %s ignoré : code d’unité « %s » déjà utilisé.', $sourceId, $code);
                    unset($pending[$sourceId]);
                    $progress = true;
                    continue;
                }
                $codesPlanifies[$codeKey] = $sourceId;
                $codesParSource[$sourceId] = $code;
                $typeCode = strtoupper(trim((string) $service[$typeColumn]));
                $data = [
                    'tenant_id' => $tenantId,
                    'parent_id' => $parentId,
                    'nom' => trim((string) $service[$nameColumn]),
                    'code' => $code,
                    'type' => $this->mapperType($typeCode),
                    'niveau' => $this->mapperNiveau($typeCode),
                    'actif' => 1,
                    'source_id' => (int) $sourceId,
                    'source_systeme' => self::SOURCE_SYSTEME,
                    'source_synced_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($existing !== null) {
                    $mapping[$sourceId] = (int) $existing['id'];
                    if (! $dryRun && (int) ($existing['ams_override'] ?? 0) !== 1) {
                        if (! $this->amsDb->table('unites_organisationnelles')->where('id', (int) $existing['id'])->update($data)) {
                            throw new RuntimeException('Mise à jour unité GPIO refusée pour la source ' . $sourceId . '.');
                        }
                    }
                    $stats['maj']++;
                } else {
                    $ordre++;
                    $data['ordre_affichage'] = $ordre;
                    if ($dryRun) {
                        $mapping[$sourceId] = -$ordre;
                    } else {
                        $data['created_at'] = date('Y-m-d H:i:s');
                        if (! $this->amsDb->table('unites_organisationnelles')->insert($data)) {
                            throw new RuntimeException('Insertion unité GPIO refusée pour la source ' . $sourceId . '.');
                        }
                        $mapping[$sourceId] = (int) $this->amsDb->insertID();
                    }
                    $stats['crees']++;
                }

                unset($pending[$sourceId]);
                $progress = true;
            }

            if (! $progress) {
                foreach (array_keys($pending) as $sourceId) {
                    $stats['erreurs']++;
                    $stats['details'][] = sprintf('Service source %s ignoré : cycle ou parent non résolu.', $sourceId);
                }
                break;
            }
        }

        return $stats;
    }

    /**
     * Prépare les comptes des chefs dont l'employé existe déjà dans AMS.
     *
     * @return array{traites:int,crees_avec_email:int,crees_sans_email:int,deja_existants:int,ignores:int,erreurs:int,raisons:array<string,int>,details:list<string>}
     */
    public function creerComptesChefs(int $tenantId, bool $dryRun = true): array
    {
        $this->verifierTenant($tenantId);
        $chefs = $this->gpioDb->table('etau_service_chef')->get()->getResultArray();
        $stats = [
            'traites' => count($chefs),
            'crees_avec_email' => 0,
            'crees_sans_email' => 0,
            'deja_existants' => 0,
            'ignores' => 0,
            'erreurs' => 0,
            'roles' => [],
            'raisons' => [],
            'details' => [],
        ];
        if ($chefs === []) {
            return $stats;
        }

        $serviceColumn = $this->colonne($chefs, ['serviceID', 'service_id', 'id_service']);
        $userColumn = $this->colonne($chefs, ['utilisateur_id', 'utilisateurID', 'userID', 'user_id', 'id_utilisateur']);
        $statusColumn = $this->colonne($chefs, ['estactif', 'actif', 'status']);
        $chefIdColumn = $this->colonne($chefs, ['id', 'service_chef_id', 'id_service_chef']);
        if ($serviceColumn === null || $userColumn === null || $statusColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_service_chef incompatible : serviceID, utilisateur_id et estactif sont requis.');
        }

        $gpioUsers = $this->chargerUtilisateursGpio();
        $usersBySourceId = [];
        foreach ($gpioUsers as $gpioUser) {
            $idColumn = $this->colonne([$gpioUser], ['utilisateur_id', 'utilisateurID', 'userID', 'id_utilisateur', 'id']);
            if ($idColumn !== null) {
                $usersBySourceId[trim((string) $gpioUser[$idColumn])] = $gpioUser;
            }
        }

        $employees = $this->amsDb->table('employes')
            ->select('id, matricule, user_id_capteur, source_id, nom, prenom, telephone')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $employeesBySource = [];
        $employeesByMatricule = [];
        $employeesBySensorId = [];
        foreach ($employees as $employee) {
            $this->indexerEmploye($employeesBySource, $employee['source_id'] ?? null, $employee);
            $this->indexerEmploye($employeesByMatricule, $employee['matricule'] ?? null, $employee);
            $this->indexerEmploye($employeesBySensorId, $employee['user_id_capteur'] ?? null, $employee);
        }

        $units = $this->amsDb->table('unites_organisationnelles')
            ->select('id, source_id, type')
            ->where('tenant_id', $tenantId)
            ->where('source_systeme', self::SOURCE_SYSTEME)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $unitsBySource = [];
        foreach ($units as $unit) {
            $unitsBySource[(string) $unit['source_id']] = $unit;
        }

        $accounts = $this->amsDb->table('users')
            ->select('id, employe_id, email, deleted_at')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getResultArray();
        $accountsByEmployee = [];
        $accountsByEmail = [];
        foreach ($accounts as $account) {
            if ($account['employe_id'] !== null) {
                $accountsByEmployee[(int) $account['employe_id']][] = $account;
            }
            if ($account['email'] !== null && trim((string) $account['email']) !== '') {
                $accountsByEmail[mb_strtolower(trim((string) $account['email']))][] = $account;
            }
        }

        $emailColumn = $this->colonne($gpioUsers, ['email', 'mail', 'courriel', 'email_address']);
        $candidatesByEmployee = [];
        $plannedEmails = [];
        foreach ($chefs as $chef) {
            if ((int) $chef[$statusColumn] !== 1) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'chef_inactif', 'Mandat GPIO inactif.');
                continue;
            }

            $gpioUserId = trim((string) $chef[$userColumn]);
            $gpioUser = $usersBySourceId[$gpioUserId] ?? null;
            if ($gpioUser === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'utilisateur_gpio_introuvable', 'Utilisateur GPIO de chef introuvable ou non classé type U.');
                continue;
            }

            $matriculeColumn = $this->colonne([$gpioUser], ['matricule', 'employee_id', 'numero_matricule']);
            $matricule = $matriculeColumn === null ? '' : trim((string) $gpioUser[$matriculeColumn]);
            $employee = $this->obtenirEmployeIndexe($employeesBySource, $gpioUserId)
                ?? ($matricule === '' ? null : $this->obtenirEmployeIndexe($employeesByMatricule, $matricule))
                ?? $this->obtenirEmployeIndexe($employeesBySensorId, $gpioUserId);
            if ($employee === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'employe_ams_introuvable', 'Aucun employé AMS ne correspond à ce chef GPIO.');
                continue;
            }

            $serviceId = trim((string) $chef[$serviceColumn]);
            $unit = $unitsBySource[$serviceId] ?? null;
            if ($unit === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'unite_ams_introuvable', 'Aucune unité AMS importée ne correspond au service du chef.');
                continue;
            }

            $role = match ((string) $unit['type']) {
                'COMMISSARIAT' => 'commissaire',
                'DIRECTION' => 'directeur',
                'SERVICE' => 'chef_service',
                'EQUIPE' => 'superviseur',
                default => null,
            };
            if ($role === null) {
                $stats['erreurs']++;
                $this->ajouterRaisonIgnore($stats, 'type_unite_invalide', 'Type d’unité sans rôle de chef correspondant.');
                continue;
            }

            $employeeId = (int) $employee['id'];
            if (isset($accountsByEmployee[$employeeId])) {
                $stats['deja_existants']++;
                $this->ajouterRaisonIgnore($stats, 'compte_deja_existant', 'Un compte utilisateur est déjà associé à cet employé.');
                continue;
            }

            $email = $emailColumn === null ? '' : trim((string) ($gpioUser[$emailColumn] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $stats['erreurs']++;
                $this->ajouterRaisonIgnore($stats, 'email_gpio_invalide', 'Adresse email GPIO non vide mais invalide; compte non créé.');
                continue;
            }
            $email = $email === '' ? null : mb_strtolower($email);
            if ($email !== null && isset($accountsByEmail[$email])) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'email_deja_utilise', 'Adresse email déjà associée à un compte du tenant.');
                continue;
            }
            if ($email !== null && isset($plannedEmails[$email])) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'email_gpio_duplique', 'Adresse email répétée dans les chefs GPIO; compte ignoré pour éviter un conflit.');
                continue;
            }
            if ($email !== null) {
                $plannedEmails[$email] = true;
            }

            $candidate = [
                'chef_id' => $chefIdColumn === null ? null : (int) $chef[$chefIdColumn],
                'employee_id' => $employeeId,
                'unit_type' => (string) $unit['type'],
                'role' => $role,
                'email' => $email,
                'employee' => $employee,
            ];
            $candidatesByEmployee[$employeeId][] = $candidate;
        }

        foreach ($candidatesByEmployee as $employeeId => $candidates) {
            usort($candidates, static function (array $left, array $right): int {
                $rank = ['commissaire' => 4, 'directeur' => 3, 'chef_service' => 2, 'superviseur' => 1];
                $roleRank = $rank[$right['role']] <=> $rank[$left['role']];

                return $roleRank !== 0 ? $roleRank : (($left['chef_id'] ?? 0) <=> ($right['chef_id'] ?? 0));
            });
            if (count($candidates) > 1) {
                $stats['ignores'] += count($candidates) - 1;
                $this->ajouterRaisonIgnore(
                    $stats,
                    'employe_chef_multiple',
                    'Employé présent dans plusieurs mandats actifs; un seul compte est prévu avec le rôle du mandat hiérarchique le plus élevé.'
                );
            }

            $candidate = $candidates[0];
            $employee = $candidate['employee'];
            $name = trim(implode(' ', array_filter([
                trim((string) ($employee['nom'] ?? '')),
                trim((string) ($employee['prenom'] ?? '')),
            ], static fn (string $part): bool => $part !== '')));
            $data = [
                'tenant_id' => $tenantId,
                'employe_id' => $employeeId,
                'email' => $candidate['email'],
                'password_hash' => null,
                'nom_complet' => $name === '' ? null : $name,
                'telephone' => $employee['telephone'] ?? null,
                'role' => $candidate['role'],
                'is_super_admin' => 0,
                'is_active' => 1,
                'auth_source' => $candidate['email'] === null ? 'LOCAL' : 'LDAP',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if (! $dryRun) {
                if (! $this->amsDb->table('users')->insert($data)) {
                    $stats['erreurs']++;
                    $this->ajouterRaisonIgnore($stats, 'insertion_refusee', 'Insertion du compte utilisateur refusée par AMS.');
                    continue;
                }
            }

            if ($candidate['email'] === null) {
                $stats['crees_sans_email']++;
            } else {
                $stats['crees_avec_email']++;
            }
            $stats['roles'][$candidate['role']] = ($stats['roles'][$candidate['role']] ?? 0) + 1;
            $accountsByEmployee[$employeeId][] = ['email' => $candidate['email']];
            if ($candidate['email'] !== null) {
                $accountsByEmail[$candidate['email']][] = ['employe_id' => $employeeId];
            }
        }

        return $stats;
    }

    /**
     * Importe les mandats de chefs lorsque leur service, leur employé et leur compte AMS sont liés.
     *
     * @return array{lues:int,crees:int,maj:int,ignores:int,erreurs:int,raisons:array<string,int>,details:list<string>}
     */
    public function importerChefs(int $tenantId, bool $dryRun = true): array
    {
        $this->verifierTenant($tenantId);
        $rows = $this->gpioDb->table('etau_service_chef')->get()->getResultArray();
        $stats = $this->nouvellesStats(count($rows));
        if ($rows === []) {
            return $stats;
        }

        $serviceColumn = $this->colonne($rows, ['serviceID', 'service_id', 'id_service']);
        $userColumn = $this->colonne($rows, ['utilisateur_id', 'utilisateurID', 'userID', 'user_id', 'id_utilisateur']);
        if ($serviceColumn === null || $userColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_service_chef incompatible : identifiants service et utilisateur requis.');
        }
        $statusColumn = $this->colonne($rows, ['estactif', 'actif', 'status']);
        $serviceMap = $this->chargerUnitesSource($tenantId);
        $users = $this->chargerUtilisateursGpio();
        $usersById = [];
        foreach ($users as $gpioUser) {
            $idColumn = $this->colonne([$gpioUser], ['utilisateur_id', 'utilisateurID', 'userID', 'id_utilisateur', 'id']);
            if ($idColumn !== null) {
                $usersById[trim((string) $gpioUser[$idColumn])] = $gpioUser;
            }
        }
        $employes = $this->amsDb->table('employes')
            ->select('id, matricule, user_id_capteur, source_id')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $employesParSource = [];
        $employesParMatricule = [];
        $employesParUserCapteur = [];
        foreach ($employes as $employe) {
            $this->indexerEmploye($employesParSource, $employe['source_id'] ?? null, $employe);
            $this->indexerEmploye($employesParMatricule, $employe['matricule'] ?? null, $employe);
            $this->indexerEmploye($employesParUserCapteur, $employe['user_id_capteur'] ?? null, $employe);
        }

        foreach ($rows as $row) {
            if ($statusColumn !== null && (int) $row[$statusColumn] !== 1) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'chef_inactif', 'Mandat GPIO inactif.');
                continue;
            }

            $serviceId = (string) $row[$serviceColumn];
            $gpioUserId = trim((string) $row[$userColumn]);
            $uniteId = $serviceMap[$serviceId] ?? null;
            $gpioUser = $usersById[$gpioUserId] ?? null;
            if ($gpioUser === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore(
                    $stats,
                    'utilisateur_gpio_introuvable',
                    sprintf('Mandat service %s : utilisateur GPIO %s introuvable.', $serviceId, $gpioUserId)
                );
                continue;
            }

            $gpioMatriculeColumn = $this->colonne([$gpioUser], ['matricule', 'employee_id', 'numero_matricule']);
            $gpioMatricule = $gpioMatriculeColumn === null ? '' : trim((string) $gpioUser[$gpioMatriculeColumn]);
            $matchedEmployee = $this->obtenirEmployeIndexe($employesParSource, $gpioUserId)
                ?? ($gpioMatricule === '' ? null : $this->obtenirEmployeIndexe($employesParMatricule, $gpioMatricule))
                ?? $this->obtenirEmployeIndexe($employesParUserCapteur, $gpioUserId);
            if ($uniteId === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore(
                    $stats,
                    'unite_introuvable',
                    sprintf('Mandat service %s : unité source non importée.', $serviceId)
                );
                continue;
            }
            if ($matchedEmployee === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore(
                    $stats,
                    $gpioMatricule === '' ? 'matricule_gpio_absent' : 'employe_ams_introuvable',
                    $gpioMatricule === ''
                        ? sprintf('Mandat utilisateur GPIO %s : matricule absent; aucun employé AMS associé.', $gpioUserId)
                        : sprintf('Mandat utilisateur GPIO %s : aucun employé AMS associé au matricule fourni.', $gpioUserId)
                );
                continue;
            }
            $userId = $this->trouverComptePourEmploye($tenantId, $matchedEmployee['id']);
            if ($userId === null) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore(
                    $stats,
                    'compte_ams_absent',
                    sprintf('Mandat utilisateur GPIO %s : employé AMS #%d sans compte utilisateur lié.', $gpioUserId, $matchedEmployee['id'])
                );
                continue;
            }

            $dateDebutColumn = $this->colonne([$row], ['date_debut', 'datedebut', 'date_debut_mandat']);
            $dateFinColumn = $this->colonne([$row], ['date_fin', 'datefin', 'date_fin_mandat']);
            $dateDebut = $dateDebutColumn === null ? date('Y-m-d') : $this->dateValide((string) $row[$dateDebutColumn]);
            if ($dateDebut === null) {
                $stats['erreurs']++;
                $stats['details'][] = 'Mandat ignoré : date de début invalide.';
                continue;
            }
            $dateFin = $dateFinColumn === null || trim((string) $row[$dateFinColumn]) === ''
                ? null
                : $this->dateValide((string) $row[$dateFinColumn]);
            if ($dateFinColumn !== null && trim((string) $row[$dateFinColumn]) !== '' && $dateFin === null) {
                $stats['erreurs']++;
                $stats['details'][] = 'Mandat ignoré : date de fin invalide.';
                continue;
            }

            $sourceIdColumn = $this->colonne([$row], ['id', 'service_chef_id', 'id_service_chef']);
            $sourceId = $sourceIdColumn === null ? null : (int) $row[$sourceIdColumn];
            $data = [
                'tenant_id' => $tenantId,
                'unite_id' => $uniteId,
                'user_id' => $userId,
                'type_mandat' => 'TITULAIRE',
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'est_actif' => $dateFin === null || $dateFin >= date('Y-m-d') ? 1 : 0,
                'source_id' => $sourceId,
                'source_systeme' => self::SOURCE_SYSTEME,
                'source_synced_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $existing = $sourceId === null ? null : $this->amsDb->table('unit_chefs')
                ->where('tenant_id', $tenantId)
                ->where('source_systeme', self::SOURCE_SYSTEME)
                ->where('source_id', $sourceId)
                ->get()
                ->getRowArray();
            if ($existing === null) {
                $existing = $this->amsDb->table('unit_chefs')
                    ->where('tenant_id', $tenantId)
                    ->where('unite_id', $uniteId)
                    ->where('user_id', $userId)
                    ->where('date_debut', $dateDebut)
                    ->get()
                    ->getRowArray();
            }

            if ($existing !== null) {
                if (! $dryRun && (int) ($existing['ams_override'] ?? 0) !== 1) {
                    if (! $this->amsDb->table('unit_chefs')->where('id', (int) $existing['id'])->update($data)) {
                        throw new RuntimeException('Mise à jour du mandat GPIO refusée.');
                    }
                }
                $stats['maj']++;
            } else {
                if (! $dryRun) {
                    $data['created_at'] = date('Y-m-d H:i:s');
                    if (! $this->amsDb->table('unit_chefs')->insert($data)) {
                        throw new RuntimeException('Insertion du mandat GPIO refusée.');
                    }
                }
                $stats['crees']++;
            }
        }

        return $stats;
    }

    /**
     * Affecte les employés AMS aux unités source déjà importées.
     *
     * @return array{lues:int,crees:int,maj:int,ignores:int,erreurs:int,raisons:array<string,int>,details:list<string>}
     */
    public function assignerEmployesAuxUnites(int $tenantId, bool $dryRun = true): array
    {
        $this->verifierTenant($tenantId);
        $rows = $this->gpioDb->table('etau_utilisateur')->get()->getResultArray();
        if ($rows === []) {
            return $this->nouvellesStats(0);
        }

        $idColumn = $this->colonne($rows, ['utilisateurID', 'userID', 'id_utilisateur', 'id']);
        $serviceColumn = $this->colonne($rows, ['serviceID', 'service_id', 'id_service']);
        if ($idColumn === null || $serviceColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_utilisateur incompatible : identifiants utilisateur et service requis.');
        }
        $typeColumn = $this->colonne($rows, ['typeObjet', 'type_objet']);
        if ($typeColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_utilisateur incompatible : la colonne typeObjet est requise.');
        }
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => strtoupper(trim((string) $row[$typeColumn])) === 'U'
        ));
        $stats = $this->nouvellesStats(count($rows));
        if ($rows === []) {
            return $stats;
        }
        $activeColumn = $this->colonne($rows, ['utilisateur_active', 'actif', 'status']);
        $matriculeColumn = $this->colonne($rows, ['matricule', 'employee_id', 'numero_matricule']);
        $uniteParSource = $this->chargerUnitesSource($tenantId);

        $employes = $this->amsDb->table('employes')
            ->select('id, matricule, user_id_capteur, source_id')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $employesParSource = [];
        $employesParMatricule = [];
        $employesParUserCapteur = [];
        foreach ($employes as $employe) {
            $this->indexerEmploye($employesParSource, $employe['source_id'] ?? null, $employe);
            $this->indexerEmploye($employesParMatricule, $employe['matricule'] ?? null, $employe);
            $this->indexerEmploye($employesParUserCapteur, $employe['user_id_capteur'] ?? null, $employe);
        }

        foreach ($rows as $row) {
            if ($activeColumn !== null && (int) $row[$activeColumn] !== 1) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'utilisateur_inactif', 'Utilisateur GPIO inactif.');
                continue;
            }

            $gpioUserId = trim((string) $row[$idColumn]);
            $serviceId = trim((string) $row[$serviceColumn]);
            $matricule = $matriculeColumn === null ? '' : trim((string) $row[$matriculeColumn]);
            $employe = $this->obtenirEmployeIndexe($employesParSource, $gpioUserId)
                ?? ($matricule === '' ? null : $this->obtenirEmployeIndexe($employesParMatricule, $matricule))
                ?? $this->obtenirEmployeIndexe($employesParUserCapteur, $gpioUserId);
            $uniteId = $uniteParSource[$serviceId] ?? null;
            if ($employe === null || $uniteId === null) {
                $stats['ignores']++;
                if ($employe === null) {
                    $reason = $matricule === '' ? 'employe_ams_introuvable_sans_matricule' : 'employe_ams_introuvable';
                    $detail = sprintf(
                        'Utilisateur GPIO %s ignoré : aucun employé AMS associé%s.',
                        $gpioUserId,
                        $matricule === '' ? ' (matricule source absent)' : ' au matricule fourni'
                    );
                    $this->ajouterRaisonIgnore($stats, $reason, $detail);
                }
                if ($uniteId === null) {
                    $this->ajouterRaisonIgnore(
                        $stats,
                        'unite_source_introuvable',
                        sprintf('Utilisateur GPIO %s ignoré : service source %s sans unité AMS importée.', $gpioUserId, $serviceId)
                    );
                }
                continue;
            }
            $employeId = (int) $employe['id'];

            $employe = $this->amsDb->table('employes')
                ->select('unite_id')
                ->where('id', $employeId)
                ->where('tenant_id', $tenantId)
                ->get()
                ->getRowArray();
            if ($employe === null) {
                $stats['erreurs']++;
                $this->ajouterRaisonIgnore($stats, 'employe_ams_introuvable', sprintf('Employé AMS #%d introuvable dans le tenant.', $employeId));
                continue;
            }

            if ((int) ($employe['unite_id'] ?? 0) === $uniteId) {
                $stats['ignores']++;
                $this->ajouterRaisonIgnore($stats, 'unite_deja_assignee', sprintf('Employé AMS #%d déjà associé à cette unité.', $employeId));
                continue;
            }

            if (! $dryRun) {
                $this->amsDb->table('employes')
                    ->where('id', $employeId)
                    ->where('tenant_id', $tenantId)
                    ->update(['unite_id' => $uniteId, 'updated_at' => date('Y-m-d H:i:s')]);
                if ($this->amsDb->affectedRows() !== 1) {
                    throw new RuntimeException(sprintf('Mise à jour de l’employé AMS #%d échouée.', $employeId));
                }
            }
            $stats['maj']++;
        }

        return $stats;
    }

    private function mapperType(string $wichIs): string
    {
        return match (strtoupper(trim($wichIs))) {
            'A', 'G', 'J', 'C' => 'COMMISSARIAT',
            'D' => 'DIRECTION',
            'S' => 'SERVICE',
            'E' => 'EQUIPE',
            default => throw new RuntimeException('Type de service GPIO non pris en charge.'),
        };
    }

    private function mapperNiveau(string $wichIs): int
    {
        return match (strtoupper(trim($wichIs))) {
            'A' => 0,
            'G', 'J', 'C' => 1,
            'D' => 2,
            'S' => 3,
            'E' => 4,
            default => throw new RuntimeException('Niveau de service GPIO non pris en charge.'),
        };
    }

    /**
     * @return array<string,int>
     */
    private function chargerUnitesSource(int $tenantId): array
    {
        $rows = $this->amsDb->table('unites_organisationnelles')
            ->select('id, source_id')
            ->where('tenant_id', $tenantId)
            ->where('source_systeme', self::SOURCE_SYSTEME)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $resultat = [];
        foreach ($rows as $row) {
            $resultat[(string) $row['source_id']] = (int) $row['id'];
        }

        return $resultat;
    }

    private function chargerUtilisateursGpio(): array
    {
        $rows = $this->gpioDb->table('etau_utilisateur')->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $typeColumn = $this->colonne($rows, ['typeObjet', 'type_objet']);
        if ($typeColumn === null) {
            throw new RuntimeException('Schéma GPIO etau_utilisateur incompatible : la colonne typeObjet est requise.');
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => strtoupper(trim((string) $row[$typeColumn])) === 'U'
        ));
    }

    private function trouverComptePourEmploye(int $tenantId, int $employeId): ?int
    {
        $user = $this->amsDb->table('users')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $user === null ? null : (int) $user['id'];
    }

    /**
     * @param array<string,array<string,mixed>|null> $index
     * @param array<string,mixed> $employe
     */
    private function indexerEmploye(array &$index, mixed $key, array $employe): void
    {
        if ($key === null || trim((string) $key) === '') {
            return;
        }
        $normalized = trim((string) $key);
        if (array_key_exists($normalized, $index)) {
            if (($index[$normalized]['id'] ?? null) !== ($employe['id'] ?? null)) {
                $index[$normalized] = null;
            }

            return;
        }

        $index[$normalized] = $employe;
    }

    /**
     * @param array<string,array<string,mixed>|null> $index
     * @return array<string,mixed>|null
     */
    private function obtenirEmployeIndexe(array $index, string $key): ?array
    {
        $employe = $index[trim($key)] ?? null;

        return is_array($employe) ? $employe : null;
    }

    /**
     * Ajoute un compte de motif et conserve au maximum dix exemples sans données personnelles.
     *
     * @param array<string,mixed> $stats
     */
    private function ajouterRaisonIgnore(array &$stats, string $raison, string $detail): void
    {
        $stats['raisons'][$raison] = ($stats['raisons'][$raison] ?? 0) + 1;
        if (count($stats['details']) < 10) {
            $stats['details'][] = $detail;
        }
    }

    private function verifierTenant(int $tenantId): void
    {
        if ($tenantId < 1 || $this->amsDb->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() !== 1) {
            throw new RuntimeException('Tenant AMS introuvable.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param list<string> $candidats
     */
    private function colonne(array $rows, array $candidats): ?string
    {
        if ($rows === []) {
            return null;
        }
        $colonnes = array_fill_keys(array_map(
            static fn (string $nom): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $nom)),
            array_keys($rows[0])
        ), true);
        foreach ($candidats as $candidat) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $candidat));
            if (isset($colonnes[$key])) {
                foreach (array_keys($rows[0]) as $nom) {
                    if (strtolower((string) preg_replace('/[^a-z0-9]/i', '', $nom)) === $key) {
                        return (string) $nom;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{lues:int,crees:int,maj:int,ignores:int,erreurs:int,raisons:array<string,int>,details:list<string>}
     */
    private function nouvellesStats(int $lues): array
    {
        return ['lues' => $lues, 'crees' => 0, 'maj' => 0, 'ignores' => 0, 'erreurs' => 0, 'raisons' => [], 'details' => []];
    }

    private function acronymeDistinctif(string $nom): string
    {
        $nom = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom);
        if ($nom === false) {
            return '';
        }

        $mots = preg_split('/[^A-Z0-9]+/', strtoupper($nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $motsGeneriques = ['SERVICE', 'DE', 'DES', 'DU', 'LA', 'LE', 'LES', 'ET', 'D'];
        $significatifs = [];
        foreach ($mots as $mot) {
            if (! in_array($mot, $motsGeneriques, true)) {
                $significatifs[] = $mot;
            }
        }

        if (count($significatifs) === 1) {
            return substr($significatifs[0], 0, 3);
        }

        return implode('', array_map(static fn (string $mot): string => $mot[0], $significatifs));
    }

    /**
     * @param array<string,int> $repartition
     */
    private function formaterRepartition(array $repartition): string
    {
        $elements = [];
        foreach ($repartition as $valeur => $nombre) {
            $elements[] = sprintf('%s=%d', $valeur, $nombre);
        }

        return implode(', ', $elements);
    }

    private function dateValide(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10));

        return $date !== false && $date->format('Y-m-d') === substr(trim($value), 0, 10)
            ? $date->format('Y-m-d')
            : null;
    }
}
