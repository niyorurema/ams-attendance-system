<?php

namespace App\Services;

use App\Libraries\JwtTokenService;
use App\Models\TenantModel;
use App\Models\UserModel;
use Config\Database;
use Throwable;

/**
 * Unifie les authentifications locale, LDAP et hybride.
 */
class AuthService
{
    protected TenantModel $tenantModel;
    protected UserModel $userModel;
    protected LdapAuthService $ldapAuthService;
    protected JwtTokenService $jwtTokenService;

    public function __construct(
        ?TenantModel $tenantModel = null,
        ?UserModel $userModel = null,
        ?LdapAuthService $ldapAuthService = null,
        ?JwtTokenService $jwtTokenService = null
    ) {
        $this->tenantModel = $tenantModel ?? new TenantModel();
        $this->userModel = $userModel ?? new UserModel();
        $this->ldapAuthService = $ldapAuthService ?? new LdapAuthService();
        $this->jwtTokenService = $jwtTokenService ?? new JwtTokenService();
    }

    /**
     * Authentifie un utilisateur selon le mode configuré pour le tenant.
     *
     * @param array<string, mixed> $options Options réservées au contexte appelant.
     * @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string}
     */
    public function login(int $tenantId, string $email, string $password, array $options = []): array
    {
        unset($options);
        $email = strtolower(trim($email));
        $tenant = $this->tenantModel->find($tenantId);
        if (! is_array($tenant) || (int) $tenant['is_active'] !== 1) {
            return $this->echec('Tenant introuvable ou inactif.');
        }

        $mode = strtoupper((string) ($tenant['auth_mode'] ?? 'LOCAL'));
        return match ($mode) {
            'LDAP' => $this->loginLdap($tenantId, $email, $password),
            'HYBRIDE' => $this->loginHybride($tenantId, $email, $password),
            default => $this->loginLocal($tenantId, $email, $password),
        };
    }

    /**
     * Authentifie par email lorsque le tenant n'est pas fourni.
     * Les utilisateurs supprimés logiquement sont exclus par UserModel.
     *
     * @param array<string, mixed> $options Options réservées au contexte appelant.
     * @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string}
     */
    public function loginParEmail(string $email, string $password, array $options = []): array
    {
        $email = strtolower(trim($email));
        $users = $this->userModel->where('email', $email)->findAll();

        if ($users === []) {
            return $this->echec('Identifiants invalides.');
        }

        if (count($users) > 1) {
            return $this->echec('Cet email est associé à plusieurs entreprises. Contactez votre administrateur.');
        }

        $tenantId = filter_var($users[0]['tenant_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            return $this->echec('Identifiants invalides.');
        }

        return $this->login((int) $tenantId, $email, $password, $options);
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    public function loginLocal(int $tenantId, string $email, string $password): array
    {
        $user = $this->trouverUtilisateur($tenantId, $email);
        if ($user === null || ! password_verify($password, (string) $user['password_hash'])) {
            $this->logAuth($tenantId, null, $email, 'LOCAL', 'ECHEC_MDP', 'Identifiants locaux invalides.');
            return $this->echec('Identifiants invalides.');
        }
        return $this->finaliserConnexion($user, 'LOCAL');
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    public function loginLdap(int $tenantId, string $email, string $password): array
    {
        try {
            $username = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
            $ldapUser = $this->ldapAuthService->authenticate($tenantId, $username, $password);
            if ($ldapUser === null) {
                return $this->echec('Identifiants LDAP invalides.');
            }
            $user = $this->trouverUtilisateur($tenantId, (string) ($ldapUser['email'] ?? $email));
            if ($user === null) {
                $this->logAuth($tenantId, null, $email, 'LDAP', 'COMPTE_INACTIF', 'Compte LDAP non synchronisé ou inactif.');
                return $this->echec('Compte utilisateur indisponible.');
            }
            return $this->finaliserConnexion($user, 'LDAP');
        } catch (Throwable $exception) {
            $this->logAuth($tenantId, null, $email, 'LDAP', 'ECHEC_LDAP', $exception->getMessage());
            return $this->echec('Service LDAP indisponible.');
        }
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    protected function loginHybride(int $tenantId, string $email, string $password): array
    {
        $ldap = $this->loginLdap($tenantId, $email, $password);
        return $ldap['success'] ? $ldap : $this->loginLocal($tenantId, $email, $password);
    }

    /**
     * Journalise une tentative sans jamais conserver le mot de passe.
     */
    public function logAuth(int $tenantId, ?int $userId, string $email, string $source, string $statut, ?string $message = null): void
    {
        Database::connect()->table('auth_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'email' => mb_substr($email, 0, 150),
            'auth_source' => $source,
            'statut' => $statut,
            'message_erreur' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return ?array<string, mixed> */
    protected function trouverUtilisateur(int $tenantId, string $email): ?array
    {
        $user = $this->userModel->where('tenant_id', $tenantId)->where('email', $email)->first();
        return is_array($user) && (int) $user['is_active'] === 1 ? $user : null;
    }

    /** @return array{success:bool,user:?array,token:?string,auth_source:?string,message:?string} */
    protected function finaliserConnexion(array $user, string $source): array
    {
        try {
            $jeton = $this->jwtTokenService->issueForUser($user);
            $this->userModel->update((int) $user['id'], ['derniere_connexion' => date('Y-m-d H:i:s')]);
            $this->logAuth((int) $user['tenant_id'], (int) $user['id'], (string) $user['email'], $source, 'SUCCESS');
            unset($user['password_hash']);
            return ['success' => true, 'user' => $user, 'token' => $jeton['access_token'], 'auth_source' => $source, 'message' => null];
        } catch (Throwable $exception) {
            log_message('error', 'Finalisation authentification utilisateur #{id} impossible : {error}', [
                'id' => $user['id'] ?? 0,
                'error' => $exception->getMessage(),
            ]);
            return $this->echec('Impossible de créer le jeton.');
        }
    }

    /** @return array{success:false,user:null,token:null,auth_source:null,message:string} */
    protected function echec(string $message): array
    {
        return ['success' => false, 'user' => null, 'token' => null, 'auth_source' => null, 'message' => $message];
    }
}
