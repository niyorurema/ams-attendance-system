<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Authentifie et synchronise les comptes Active Directory avec bascule LDAP.
 */
class LdapAuthService
{
    protected const TIMEOUT_SECONDES = 5;
    protected BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Authentifie un utilisateur AD en essayant les serveurs configurés.
     *
     * @return ?array<string, ?string> Attributs mappés, ou null si le bind utilisateur échoue.
     */
    public function authenticate(int $tenantId, string $username, string $password): ?array
    {
        if ($username === '' || $password === '') {
            $this->journaliser($tenantId, $username, 'ECHEC_MDP', 'Identifiants LDAP incomplets.');
            return null;
        }

        $configuration = $this->getConfigurationForTenant($tenantId);
        $derniereErreur = 'Aucun serveur LDAP configuré.';
        foreach ($this->getLdapHosts($tenantId) as $host) {
            $connexion = null;
            try {
                $connexion = $this->ouvrirConnexion($host, $configuration['port']);
                $this->configurerConnexion($connexion);
                $this->activerTls($connexion, (bool) ($configuration['use_tls'] ?? false));
                if (! $this->effectuerBind($connexion, $configuration['bind_dn'], $configuration['bind_password'])) {
                    throw new \RuntimeException('Échec du bind du compte de service LDAP.');
                }

                $filtre = str_replace('{username}', $this->echapperFiltre($username), $configuration['user_filter']);
                $entree = $this->rechercherUtilisateur($connexion, $configuration['user_base_dn'], $filtre);
                if ($entree === null) {
                    $this->journaliser($tenantId, $username, 'ECHEC_MDP', 'Utilisateur LDAP introuvable.');
                    return null;
                }

                $dn = $this->getLdapAttr($entree, 'dn');
                if ($dn === null || ! $this->effectuerBind($connexion, $dn, $password)) {
                    $this->journaliser($tenantId, $username, 'ECHEC_MDP', 'Échec du bind utilisateur LDAP.');
                    return null;
                }

                $utilisateur = $this->mapperUtilisateur($entree, $username, $configuration);
                $this->journaliser($tenantId, $username, 'SUCCESS', 'Authentification LDAP réussie sur ' . $host);
                return $utilisateur;
            } catch (\Throwable $exception) {
                $derniereErreur = $exception->getMessage();
                $this->journaliser($tenantId, $username, 'ECHEC_LDAP', 'Serveur ' . $host . ' indisponible : ' . $derniereErreur);
            } finally {
                if ($connexion !== null) {
                    $this->fermerConnexion($connexion);
                }
            }
        }

        throw new \RuntimeException('Aucun serveur LDAP disponible : ' . $derniereErreur);
    }

    /** @return array{success:bool,message?:string,resultats:array<string,array{statut:string,message:string,duree_ms:int}>} */
    public function tester(int $tenantId): array
    {
        $tenant = $this->db->table('tenants')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($tenant === null) {
            return ['success' => false, 'message' => 'Tenant introuvable.', 'resultats' => []];
        }

        $configuration = $this->getConfigurationForTenant($tenantId);
        $password = (string) $configuration['bind_password'];
        $bindDn = trim((string) $configuration['bind_dn']);
        $hosts = $this->getLdapHosts($tenantId);
        if ($password === '' || $bindDn === '' || $hosts === []) {
            return $this->echecConfiguration($tenantId, 'Configurez les serveurs LDAP, le DN et le mot de passe du compte de service dans Paramètres > LDAP.');
        }
        $port = (int) $configuration['port'];
        $resultats = [];
        $success = false;

        foreach ($hosts as $host) {
            $host = trim((string) $host);
            $debut = microtime(true);
            try {
                $tentative = $this->testerBind($host, $port, $bindDn, $password, (bool) $configuration['use_tls']);
                if (! $tentative['success']) {
                    $estUneIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
                    $ip = $estUneIp ? $host : gethostbyname($host);
                    if (! $estUneIp && $ip === $host) {
                        throw new \RuntimeException(sprintf('DNS non résolu : %s. Échec initial : %s', $host, $tentative['message']));
                    }

                    if ($ip !== $host) {
                        $tentative = $this->testerBind($ip, $port, $bindDn, $password, (bool) $configuration['use_tls']);
                    }

                    if (! $tentative['success']) {
                        throw new \RuntimeException($tentative['message']);
                    }
                }

                $success = true;
                $resultats[$host] = [
                    'statut' => 'OK',
                    'message' => $tentative['message'],
                    'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
                ];
                $this->journaliser($tenantId, '', 'SUCCESS', 'Test LDAP réussi sur ' . $host . ' : ' . $tentative['message']);
            } catch (\Throwable $exception) {
                $resultats[$host] = [
                    'statut' => 'ERREUR',
                    'message' => $exception->getMessage(),
                    'duree_ms' => (int) round((microtime(true) - $debut) * 1000),
                ];
                $this->journaliser($tenantId, '', 'ECHEC_LDAP', 'Test LDAP échoué sur ' . $host . ' : ' . $exception->getMessage());
            }
        }

        $this->db->table('tenants')->where('id', $tenantId)->update([
            'ldap_derniere_verif' => date('Y-m-d H:i:s'),
            'ldap_statut' => $success ? 'OK' : 'ERREUR',
        ]);

        return ['success' => $success, 'resultats' => $resultats];
    }

    /** @return array{success:bool,message:string} */
    protected function testerBind(string $host, int $port, string $bindDn, string $password, bool $useTls = false): array
    {
        $connexion = $this->creerConnexionLdap($host, $port);
        if ($connexion === false) {
            return ['success' => false, 'message' => 'ldap_connect a échoué.'];
        }

        try {
            ldap_set_option($connexion, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connexion, LDAP_OPT_NETWORK_TIMEOUT, self::TIMEOUT_SECONDES);
            ldap_set_option($connexion, LDAP_OPT_REFERRALS, 0);
            if ($useTls && ! @ldap_start_tls($connexion)) {
                return ['success' => false, 'message' => 'Démarrage TLS LDAP refusé.'];
            }

            if (@ldap_bind($connexion, $bindDn, $password)) {
                return ['success' => true, 'message' => 'Bind OK (' . $host . ')'];
            }

            return [
                'success' => false,
                'message' => sprintf('Bind échoué (code %d) : %s', ldap_errno($connexion), ldap_error($connexion)),
            ];
        } finally {
            ldap_unbind($connexion);
        }
    }

    /** @return array{success:false,message:string,resultats:array{}} */
    protected function echecConfiguration(int $tenantId, string $message): array
    {
        $this->db->table('tenants')->where('id', $tenantId)->update([
            'ldap_derniere_verif' => date('Y-m-d H:i:s'),
            'ldap_statut' => 'ERREUR',
        ]);

        return ['success' => false, 'message' => $message, 'resultats' => []];
    }

    /**
     * Cherche des emails AD par matricule exact, puis par tokens de nom/prénom.
     *
     * @param list<array{id:int,nom:string,prenom:string,matricule?:string}> $employes
     * @return array<int, array{statut:string,email:?string,methode:?string}>
     */
    public function rechercherEmailsEmployes(int $tenantId, array $employes): array
    {
        if ($employes === []) {
            return [];
        }

        $configuration = $this->getConfigurationForTenant($tenantId);
        $hosts = $this->getLdapHosts($tenantId);
        if (
            $hosts === []
            || trim((string) $configuration['bind_dn']) === ''
            || (string) $configuration['bind_password'] === ''
            || trim((string) $configuration['user_base_dn']) === ''
        ) {
            throw new \RuntimeException('Configuration LDAP incomplète : serveur, base de recherche et compte de service requis.');
        }
        $erreursParHote = [];

        foreach ($hosts as $host) {
            $connexion = null;
            try {
                $connexion = $this->ouvrirConnexion($host, (int) $configuration['port']);
                $this->configurerConnexion($connexion);
                $this->activerTls($connexion, (bool) ($configuration['use_tls'] ?? false));
                if (! $this->effectuerBind($connexion, (string) $configuration['bind_dn'], (string) $configuration['bind_password'])) {
                    throw new \RuntimeException('Échec du bind du compte de service LDAP.');
                }

                $entreesAd = $this->rechercherUtilisateursAvecEmailObr($connexion, $configuration);
                $resultats = [];
                foreach ($employes as $employe) {
                    $nom = trim($employe['nom']);
                    $prenom = trim($employe['prenom']);
                    if ($nom === '' || $prenom === '') {
                        $resultats[$employe['id']] = ['statut' => 'nom_incomplet', 'email' => null, 'methode' => null];
                        continue;
                    }

                    $matricule = trim((string) ($employe['matricule'] ?? ''));
                    $correspondancesMatricule = [];
                    $correspondancesNom = [];
                    foreach ($entreesAd as $entree) {
                        $matriculeAd = trim((string) ($this->getLdapAttr($entree, (string) $configuration['matricule']) ?? ''));
                        if ($matriculeAd === '') {
                            $matriculeAd = trim((string) ($this->getLdapAttr($entree, 'employeeNumber') ?? ''));
                        }
                        if ($matricule !== '' && $matriculeAd !== '' && $this->normaliserIdentifiant($matricule) === $this->normaliserIdentifiant($matriculeAd)) {
                            $correspondancesMatricule[] = $entree;
                        } elseif ($this->correspondanceNomParTokens($entree, $nom, $prenom)) {
                            $correspondancesNom[] = $entree;
                        }
                    }
                    $correspondances = $correspondancesMatricule !== [] ? $correspondancesMatricule : $correspondancesNom;
                    $methode = $correspondancesMatricule !== [] ? 'matricule' : 'nom';
                    if (count($correspondances) !== 1) {
                        $resultats[$employe['id']] = [
                            'statut' => count($correspondances) > 1 ? 'ambigu' : 'introuvable',
                            'email' => null,
                            'methode' => null,
                        ];
                        continue;
                    }

                    $email = trim((string) ($this->getLdapAttr($correspondances[0], (string) $configuration['email']) ?? ''));
                    $resultats[$employe['id']] = [
                        'statut' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'trouve' : 'email_invalide_ou_absent',
                        'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($email) : null,
                        'methode' => $methode,
                    ];
                }

                return $resultats;
            } catch (\Throwable $exception) {
                $erreursParHote[] = sprintf('%s : %s', $host, $exception->getMessage());
            } finally {
                if ($connexion !== null) {
                    $this->fermerConnexion($connexion);
                }
            }
        }

        throw new \RuntimeException(
            'Recherche LDAP impossible sur tous les serveurs : '
            . ($erreursParHote === [] ? 'aucun hôte configuré.' : implode(' | ', $erreursParHote))
        );
    }

    /**
     * Charge tous les comptes utilisateurs OBR ayant un email, avec pagination LDAP.
     *
     * @param array<string, string|int> $configuration
     * @return list<array<string, mixed>>
     */
    protected function rechercherUtilisateursAvecEmailObr(mixed $connexion, array $configuration): array
    {
        $filtre = $this->filtreComptesEmailObr();
        $attributs = array_values(array_unique([
            'dn',
            'mail',
            (string) $configuration['email'],
            'displayName',
            'cn',
            'sn',
            'givenName',
            (string) $configuration['matricule'],
            'employeeNumber',
        ]));
        $cookie = '';
        $entreesToutesPages = [];
        $pages = 0;
        do {
            $pages++;
            if ($pages > 1000) {
                throw new \RuntimeException('Pagination LDAP interrompue après 1000 pages.');
            }

            $controle = [
                'oid' => '1.2.840.113556.1.4.319',
                'value' => ['size' => 500, 'cookie' => $cookie],
                'iscritical' => true,
            ];
            $recherche = @ldap_search(
                $connexion,
                (string) $configuration['user_base_dn'],
                $filtre,
                $attributs,
                false,
                0,
                self::TIMEOUT_SECONDES,
                LDAP_DEREF_NEVER,
                [$controle]
            );
            if ($recherche === false) {
                throw new \RuntimeException(sprintf(
                    'Recherche LDAP avec le filtre OBR échouée (code %d) : %s',
                    ldap_errno($connexion),
                    ldap_error($connexion)
                ));
            }

            $pageEntrees = ldap_get_entries($connexion, $recherche);
            for ($index = 0; $index < (int) ($pageEntrees['count'] ?? 0); $index++) {
                $entree = $pageEntrees[$index] ?? null;
                if (is_array($entree)) {
                    $entreesToutesPages[] = $entree;
                }
            }

            $erreur = 0;
            $serverControls = [];
            if (! ldap_parse_result($connexion, $recherche, $erreur, $matchedDn, $errorMessage, $referrals, $serverControls)) {
                throw new \RuntimeException('Lecture du résultat de pagination LDAP impossible.');
            }
            $cookieSuivant = null;
            foreach ($serverControls as $serverControl) {
                if (($serverControl['oid'] ?? null) === '1.2.840.113556.1.4.319') {
                    $valeur = $serverControl['value'] ?? null;
                    if (! is_array($valeur) || ! array_key_exists('cookie', $valeur) || ! is_string($valeur['cookie'])) {
                        throw new \RuntimeException('Le serveur a retourné un cookie de pagination invalide.');
                    }
                    $cookieSuivant = $valeur['cookie'];
                    break;
                }
            }
            if ($cookieSuivant === null) {
                throw new \RuntimeException('Le serveur LDAP n’a pas retourné le contrôle de pagination requis.');
            }
            $cookie = $cookieSuivant;
        } while ($cookie !== '');

        return $entreesToutesPages;
    }

    protected function filtreComptesEmailObr(): string
    {
        return '(&(objectClass=user)(mail=*@obr.gov.bi))';
    }

    protected function filtreRechercheEmploye(string $nom, string $prenom, string $matricule, string $attributMatricule): string
    {
        $criteres = [];
        if ($matricule !== '') {
            $criteres[] = '(' . $attributMatricule . '=' . $this->echapperFiltre($matricule) . ')';
        }

        $tokensNom = $this->tokensNom($nom);
        $tokensPrenom = $this->tokensNom($prenom);
        foreach (['displayName', 'cn'] as $attribut) {
            $criteres[] = $this->filtreNomsCompletsAvecVariantes($attribut, $nom, $prenom);
        }
        if ($tokensNom !== [] && $tokensPrenom !== []) {
            $criteres[] = '(&' . $this->filtreNomsCompletsAvecVariantes('sn', $nom, '') . $this->filtreNomsCompletsAvecVariantes('givenName', $prenom, '') . ')';
            $criteres[] = '(&' . $this->filtreNomsCompletsAvecVariantes('sn', $prenom, '') . $this->filtreNomsCompletsAvecVariantes('givenName', $nom, '') . ')';
        }

        return '(&(objectClass=user)(|' . implode('', $criteres) . '))';
    }

    /** @param list<string> $tokens */
    protected function filtreTousTokens(string $attribut, array $tokens): string
    {
        if ($tokens === []) {
            return '(' . $attribut . '=*)';
        }

        return '(&' . implode('', array_map(
            fn (string $token): string => '(' . $attribut . '=*' . $this->echapperFiltre($token) . '*)',
            $tokens
        )) . ')';
    }

    protected function filtreNomsCompletsAvecVariantes(string $attribut, string $premierNom, string $secondNom): string
    {
        $bruts = array_merge($this->tokensNomBruts($premierNom), $this->tokensNomBruts($secondNom));
        $normalises = array_merge($this->tokensNom($premierNom), $this->tokensNom($secondNom));
        if ($bruts === []) {
            return '(' . $attribut . '=*)';
        }

        $criteres = [];
        foreach ($bruts as $index => $token) {
            $normalise = $normalises[$index] ?? $token;
            $variantes = array_unique([$token, $normalise]);
            $criteres[] = count($variantes) === 1
                ? '(' . $attribut . '=*' . $this->echapperFiltre($token) . '*)'
                : '(|' . implode('', array_map(
                    fn (string $variante): string => '(' . $attribut . '=*' . $this->echapperFiltre($variante) . '*)',
                    $variantes
                )) . ')';
        }

        return '(&' . implode('', $criteres) . ')';
    }

    /** @return list<string> */
    protected function tokensNomBruts(string $nom): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($nom)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens ?: []));
    }

    protected function normaliserIdentifiant(string $identifiant): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($identifiant)));
    }

    /** @return list<string> */
    protected function tokensNom(string $nom): array
    {
        $normalise = $this->normaliserNom($nom);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalise, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($tokens ?: []));
    }

    protected function normaliserNom(string $nom): string
    {
        $nom = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $nom)));
        $decompose = \Normalizer::normalize($nom, \Normalizer::FORM_D);
        if ($decompose === false) {
            return $nom;
        }

        return (string) preg_replace('/\p{Mn}+/u', '', $decompose);
    }

    /** @param array<string, mixed> $entree */
    protected function correspondanceNomParTokens(array $entree, string $nom, string $prenom): bool
    {
        $tokensCibles = array_values(array_unique(array_merge($this->tokensNom($nom), $this->tokensNom($prenom))));
        if ($tokensCibles === []) {
            return false;
        }

        $tokensAd = [];
        foreach (['sn', 'givenName', 'displayName', 'cn'] as $attribut) {
            $valeur = $this->getLdapAttr($entree, $attribut);
            if ($valeur !== null) {
                $tokensAd = array_merge($tokensAd, $this->tokensNom($valeur));
            }
        }
        $tokensAd = array_unique($tokensAd);

        return array_diff($tokensCibles, $tokensAd) === [];
    }

    /**
     * Vérifie la base de recherche configurée en consultant le RootDSE LDAP.
     *
     * @return list<array{hote:string,base:string,source_base:string,base_trouvee:bool,racines:list<string>,erreur:?string}>
     */
    public function diagnostiquerBaseRecherche(int $tenantId): array
    {
        $configuration = $this->getConfigurationForTenant($tenantId);
        $baseDn = trim((string) $configuration['user_base_dn']);
        if ($baseDn === '') {
            throw new \RuntimeException('Aucune base de recherche LDAP n’est configurée.');
        }
        $tenant = $this->db->table('tenants')
            ->select('ldap_base_dn')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray() ?? [];
        $sourceBase = ! empty($tenant['ldap_base_dn']) ? 'tenants.ldap_base_dn' : 'tenant settings';

        $resultats = [];
        $erreurs = [];
        foreach ($this->getLdapHosts($tenantId) as $host) {
            $connexion = null;
            try {
                $connexion = $this->ouvrirConnexion($host, (int) $configuration['port']);
                $this->configurerConnexion($connexion);
                $this->activerTls($connexion, (bool) ($configuration['use_tls'] ?? false));
                if (! $this->effectuerBind($connexion, (string) $configuration['bind_dn'], (string) $configuration['bind_password'])) {
                    throw new \RuntimeException('Échec du bind du compte de service LDAP.');
                }

                $rootDse = @ldap_read($connexion, '', '(objectClass=*)', ['namingContexts'], 0, 1, self::TIMEOUT_SECONDES);
                if ($rootDse === false) {
                    throw new \RuntimeException(sprintf(
                        'Lecture du RootDSE échouée (code %d) : %s',
                        ldap_errno($connexion),
                        ldap_error($connexion)
                    ));
                }
                $rootEntries = ldap_get_entries($connexion, $rootDse);
                $namingContexts = $rootEntries['0']['namingcontexts'] ?? [];
                $racines = [];
                for ($index = 0; $index < (int) ($namingContexts['count'] ?? 0); $index++) {
                    if (isset($namingContexts[$index]) && is_string($namingContexts[$index])) {
                        $racines[] = $namingContexts[$index];
                    }
                }

                $baseSearch = @ldap_read($connexion, $baseDn, '(objectClass=*)', ['dn'], 0, 1, self::TIMEOUT_SECONDES);
                $baseFound = $baseSearch !== false
                    && (int) (ldap_get_entries($connexion, $baseSearch)['count'] ?? 0) > 0;
                $erreurBase = $baseSearch === false
                    ? sprintf('Code LDAP %d : %s', ldap_errno($connexion), ldap_error($connexion))
                    : ($baseFound ? null : 'La recherche n’a retourné aucun objet.');

                $resultats[] = [
                    'hote' => $host,
                    'base' => $baseDn,
                    'source_base' => $sourceBase,
                    'base_trouvee' => $baseFound,
                    'racines' => $racines,
                    'erreur' => $erreurBase,
                ];
            } catch (\Throwable $exception) {
                $erreurs[] = $host . ' : ' . $exception->getMessage();
            } finally {
                if ($connexion !== null) {
                    $this->fermerConnexion($connexion);
                }
            }
        }

        if ($resultats === [] && $erreurs !== []) {
            throw new \RuntimeException('Diagnostic impossible : ' . implode(' | ', $erreurs));
        }

        return $resultats;
    }

    /**
     * Vérifie si une adresse déjà associée à un compte AMS existe dans AD.
     */
    public function emailExisteDansAd(int $tenantId, string $email): bool
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        $configuration = $this->getConfigurationForTenant($tenantId);
        $hosts = $this->getLdapHosts($tenantId);
        if (
            $hosts === []
            || trim((string) $configuration['bind_dn']) === ''
            || (string) $configuration['bind_password'] === ''
            || trim((string) $configuration['user_base_dn']) === ''
        ) {
            throw new \RuntimeException('Configuration LDAP incomplète.');
        }

        $erreursParHote = [];
        foreach ($hosts as $host) {
            $connexion = null;
            try {
                $connexion = $this->ouvrirConnexion($host, (int) $configuration['port']);
                $this->configurerConnexion($connexion);
                $this->activerTls($connexion, (bool) ($configuration['use_tls'] ?? false));
                if (! $this->effectuerBind($connexion, (string) $configuration['bind_dn'], (string) $configuration['bind_password'])) {
                    throw new \RuntimeException('Échec du bind du compte de service LDAP.');
                }
                $filtre = '(&(objectClass=user)(mail=' . $this->echapperFiltre($email) . '))';
                return $this->rechercherUtilisateur($connexion, (string) $configuration['user_base_dn'], $filtre) !== null;
            } catch (\Throwable $exception) {
                $erreursParHote[] = $host . ' : ' . $exception->getMessage();
            } finally {
                if ($connexion !== null) {
                    $this->fermerConnexion($connexion);
                }
            }
        }

        throw new \RuntimeException(
            'Vérification LDAP impossible : '
            . ($erreursParHote === [] ? 'aucun hôte configuré.' : implode(' | ', $erreursParHote))
        );
    }

    /**
     * Synchronise les utilisateurs AD disposant d'une adresse e-mail.
     *
     * @return array{total:int,crees:int,maj:int,ignores:int,erreurs:int}
     */
    public function syncUtilisateurs(int $tenantId, bool $dryRun = false): array
    {
        $configuration = $this->getConfigurationForTenant($tenantId);
        $statistiques = ['total' => 0, 'crees' => 0, 'maj' => 0, 'ignores' => 0, 'erreurs' => 0];
        $derniereErreur = 'Aucun serveur LDAP configuré.';

        foreach ($this->getLdapHosts($tenantId) as $host) {
            $connexion = null;
            try {
                $connexion = $this->ouvrirConnexion($host, $configuration['port']);
                $this->configurerConnexion($connexion);
                $this->activerTls($connexion, (bool) ($configuration['use_tls'] ?? false));
                if (! $this->effectuerBind($connexion, $configuration['bind_dn'], $configuration['bind_password'])) {
                    throw new \RuntimeException('Échec du bind du compte de service LDAP.');
                }

                foreach ($this->rechercherUtilisateurs($connexion, $configuration['user_base_dn']) as $entree) {
                    $statistiques['total']++;
                    try {
                        $utilisateur = $this->mapperUtilisateur($entree, '', $configuration);
                        if (empty($utilisateur['email'])) {
                            $statistiques['ignores']++;
                            continue;
                        }

                        $existant = $this->db->table('users')
                            ->select('id')
                            ->where('tenant_id', $tenantId)
                            ->where('email', $utilisateur['email'])
                            ->where('deleted_at', null)
                            ->get()
                            ->getRowArray();

                        $donnees = [
                            'nom_complet' => trim(($utilisateur['prenom'] ?? '') . ' ' . ($utilisateur['nom'] ?? '')),
                            'is_active' => 1,
                            'auth_source' => 'LDAP',
                            'ldap_dn' => $utilisateur['dn'],
                            'ldap_derniere_sync' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ];

                        if ($existant === null) {
                            $donnees += [
                                'tenant_id' => $tenantId,
                                'email' => $utilisateur['email'],
                                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                                'role' => 'lecteur',
                                'is_super_admin' => 0,
                                'created_at' => date('Y-m-d H:i:s'),
                            ];
                            if (! $dryRun) {
                                $this->db->table('users')->insert($donnees);
                            }
                            $statistiques['crees']++;
                        } else {
                            if (! $dryRun) {
                                $this->db->table('users')->where('id', $existant['id'])->update($donnees);
                            }
                            $statistiques['maj']++;
                        }
                    } catch (\Throwable) {
                        $statistiques['erreurs']++;
                    }
                }

                if (! $dryRun) {
                    $this->journaliser($tenantId, '', 'SUCCESS', 'Synchronisation LDAP réussie sur ' . $host);
                }

                return $statistiques;
            } catch (\Throwable $exception) {
                $derniereErreur = $exception->getMessage();
                if (! $dryRun) {
                    $this->journaliser($tenantId, '', 'ECHEC_LDAP', 'Synchronisation LDAP échouée sur ' . $host . ' : ' . $derniereErreur);
                }
            } finally {
                if ($connexion !== null) {
                    $this->fermerConnexion($connexion);
                }
            }
        }

        throw new \RuntimeException('Synchronisation LDAP impossible sur tous les serveurs : ' . $derniereErreur);
    }

    /** @return array<int, string> */
    public function getLdapHosts(int $tenantId): array
    {
        $tenant = $this->db->table('tenants')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray() ?? [];

        $tenantHosts = array_values(array_unique(array_filter([
            $tenant['ldap_host'] ?? null,
            $tenant['ldap_host_secondary'] ?? null,
            $tenant['ldap_host_tertiary'] ?? null,
        ], static fn ($host): bool => is_string($host) && trim($host) !== '')));
        if ($tenantHosts !== []) {
            return $tenantHosts;
        }

        return array_values(array_unique(array_filter([
            env('LDAP_HOST_PRIMARY'),
            env('LDAP_HOST_SECONDARY'),
            env('LDAP_HOST_TERTIARY'),
        ], static fn ($host): bool => is_string($host) && trim($host) !== '')));
    }

    /** @param array<string, mixed> $entry */
    public function getLdapAttr(array $entry, string $attr): ?string
    {
        $attr = strtolower($attr);
        foreach ($entry as $cle => $valeur) {
            if (strtolower((string) $cle) === $attr) {
                if (is_array($valeur)) {
                    return isset($valeur[0]) ? (string) $valeur[0] : null;
                }

                return is_scalar($valeur) ? (string) $valeur : null;
            }
        }

        return null;
    }

    /** @return array<string, string|int|bool> */
    protected function getConfiguration(): array
    {
        return [
            'port' => (int) env('LDAP_PORT', 389),
            'bind_dn' => (string) env('LDAP_BIND_DN', ''),
            'bind_password' => (string) env('LDAP_BIND_PASSWORD', ''),
            'user_base_dn' => (string) env('LDAP_USER_BASE_DN', env('LDAP_BASE_DN', '')),
            'user_filter' => (string) env('LDAP_USER_FILTER', '(sAMAccountName={username})'),
            'email' => (string) env('LDAP_ATTR_EMAIL', 'mail'),
            'nom' => (string) env('LDAP_ATTR_NOM', 'sn'),
            'prenom' => (string) env('LDAP_ATTR_PRENOM', 'givenName'),
            'matricule' => (string) env('LDAP_ATTR_MATRICULE', 'employeeID'),
            'use_tls' => filter_var(env('LDAP_USE_TLS', false), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** @return array<string, string|int|bool> */
    protected function getConfigurationForTenant(int $tenantId): array
    {
        $configuration = $this->getConfiguration();
        $tenant = $this->db->table('tenants')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray() ?? [];

        $tenantHasLdapHosts = array_filter([
            $tenant['ldap_host'] ?? null,
            $tenant['ldap_host_secondary'] ?? null,
            $tenant['ldap_host_tertiary'] ?? null,
        ], static fn ($host): bool => is_string($host) && trim($host) !== '') !== [];
        $tenantSettings = [
            'port' => 'ldap_port',
            'user_base_dn' => 'ldap_base_dn',
            'user_filter' => 'ldap_user_filter',
            'email' => 'ldap_attr_email',
            'nom' => 'ldap_attr_nom',
            'prenom' => 'ldap_attr_prenom',
            'matricule' => 'ldap_attr_matricule',
        ];
        foreach ($tenantSettings as $configurationKey => $tenantKey) {
            $value = $tenant[$tenantKey] ?? null;
            if ($value !== null && $value !== '') {
                $configuration[$configurationKey] = $configurationKey === 'port' ? (int) $value : (string) $value;
            }
        }
        if (! $tenantHasLdapHosts && (int) ($tenant['ldap_port'] ?? 389) === 389) {
            $configuration['port'] = (int) env('LDAP_PORT', 389);
        }
        $bindDn = trim((string) ($tenant['ldap_bind_dn'] ?? ''));
        if ($bindDn !== '') {
            $configuration['bind_dn'] = $bindDn;
        }
        $configuration['bind_password'] = (new ApplicationIntegrationSettings())->decryptStoredSetting(
            $tenant['ldap_bind_password_encrypted'] ?? null
        ) ?: (string) ($tenant['ldap_bind_password'] ?? $configuration['bind_password']);
        $configuration['use_tls'] = $tenantHasLdapHosts
            ? (bool) ($tenant['ldap_use_tls'] ?? false)
            : ((bool) ($tenant['ldap_use_tls'] ?? false) || (bool) $configuration['use_tls']);

        return $configuration;
    }

    /** @param array<string, mixed> $entry @param array<string, string|int> $configuration @return array<string, ?string> */
    protected function mapperUtilisateur(array $entry, string $username, array $configuration): array
    {
        return [
            'dn' => $this->getLdapAttr($entry, 'dn'),
            'username' => $username !== '' ? $username : $this->getLdapAttr($entry, 'samaccountname'),
            'email' => $this->getLdapAttr($entry, (string) $configuration['email']),
            'nom' => $this->getLdapAttr($entry, (string) $configuration['nom']),
            'prenom' => $this->getLdapAttr($entry, (string) $configuration['prenom']),
            'matricule' => $this->getLdapAttr($entry, (string) $configuration['matricule']),
        ];
    }

    protected function ouvrirConnexion(string $host, int $port): mixed
    {
        $connexion = $this->creerConnexionLdap($host, $port);
        if ($connexion === false) {
            throw new \RuntimeException('Connexion LDAP refusée.');
        }

        return $connexion;
    }

    protected function creerConnexionLdap(string $host, int $port): mixed
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }

        return @ldap_connect(sprintf('ldap://%s:%d', $host, $port));
    }

    protected function configurerConnexion(mixed $connexion): void
    {
        ldap_set_option($connexion, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connexion, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connexion, LDAP_OPT_NETWORK_TIMEOUT, self::TIMEOUT_SECONDES);
    }

    protected function activerTls(mixed $connexion, bool $useTls): void
    {
        if ($useTls && ! @ldap_start_tls($connexion)) {
            throw new \RuntimeException('Démarrage TLS LDAP refusé.');
        }
    }

    protected function effectuerBind(mixed $connexion, string $dn, string $password): bool
    {
        return @ldap_bind($connexion, $dn, $password);
    }

    protected function fermerConnexion(mixed $connexion): void
    {
        ldap_unbind($connexion);
    }

    protected function echapperFiltre(string $value): string
    {
        return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
    }

    /** @return ?array<string,mixed> */
    protected function rechercherUtilisateur(mixed $connexion, string $baseDn, string $filtre): ?array
    {
        $recherche = @ldap_search($connexion, $baseDn, $filtre);
        if ($recherche === false) {
            throw new \RuntimeException('Recherche LDAP échouée.');
        }

        $entrees = ldap_get_entries($connexion, $recherche);
        return ($entrees['count'] ?? 0) > 0 ? $entrees[0] : null;
    }

    /** @return array<int, array<string,mixed>> */
    protected function rechercherUtilisateurs(mixed $connexion, string $baseDn): array
    {
        $recherche = @ldap_search($connexion, $baseDn, '(objectClass=user)');
        if ($recherche === false) {
            throw new \RuntimeException('Recherche LDAP échouée.');
        }

        $entrees = ldap_get_entries($connexion, $recherche);
        unset($entrees['count']);
        return array_values($entrees);
    }

    protected function journaliser(int $tenantId, string $email, string $statut, string $message): void
    {
        $this->db->table('auth_logs')->insert([
            'tenant_id' => $tenantId,
            'email' => mb_substr($email, 0, 150),
            'auth_source' => 'LDAP',
            'statut' => $statut,
            'message_erreur' => mb_substr($message, 0, 65535),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}