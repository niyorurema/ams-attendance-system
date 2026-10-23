<?php

namespace App\Controllers\Api;

use App\Services\AuthService;
use App\Services\LdapAuthService;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Authentification JWT de l’API.
 */
class AuthController extends BaseApiController
{
    /**
     * Échange les identifiants d’un tenant contre un jeton Bearer.
     */
    public function login(): ResponseInterface
    {
        $input = $this->jsonInput();

        $tenantIdInput = $input['tenant_id'] ?? null;
        $tenantId = $tenantIdInput === null
            ? null
            : filter_var($tenantIdInput, FILTER_VALIDATE_INT);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return $this->errorResponse('Email et mot de passe requis.', 400);
        }

        if ($tenantIdInput !== null && ($tenantId === false || $tenantId < 1)) {
            return $this->errorResponse('tenant_id doit être un entier positif.', 400);
        }

        $service = new AuthService();
        $options = [
            'ip' => $this->request->getIPAddress(),
            'user_agent' => $this->request->getUserAgent()->getAgentString(),
        ];

        try {
            $resultat = $tenantId === null
                ? $service->loginParEmail($email, $password, $options)
                : $service->login((int) $tenantId, $email, $password, $options);
        } catch (Throwable $e) {
            log_message('error', 'Échec login API : {error}', ['error' => $e->getMessage()]);

            return $this->errorResponse('Erreur interne pendant l’authentification.', 500);
        }

        if (! $resultat['success'] || ! is_array($resultat['user']) || ! is_string($resultat['token'])) {
            return $this->errorResponse($resultat['message'] ?? 'Identifiants invalides.', 401);
        }

        $user = $resultat['user'];
        return $this->successResponse([
            'access_token' => $resultat['token'],
            'token_type' => 'Bearer',
            'user' => [
                'id' => (int) $user['id'],
                'tenant_id' => (int) $user['tenant_id'],
                'email' => $user['email'],
                'nom_complet' => $user['nom_complet'],
                'role' => $user['role'],
                'is_super_admin' => (bool) $user['is_super_admin'],
            ],
            'expires_in' => max(60, min(86400, (int) env('JWT_TTL', 3600))),
        ], 'Connexion réussie.');
    }

    public function profile(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $user = (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->first();
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable.', 404);
        }

        return $this->successResponse([
            'id' => (int) $user['id'],
            'tenant_id' => (int) $user['tenant_id'],
            'email' => $user['email'],
            'nom_complet' => $user['nom_complet'],
            'role' => $user['role'],
            'is_super_admin' => (bool) $user['is_super_admin'],
            'derniere_connexion' => $user['derniere_connexion'],
        ]);
    }

    public function ldapTest(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        try {
            $resultat = (new LdapAuthService())->tester((int) $tenantId);
            if (! $resultat['success']) {
                return $this->response->setStatusCode(502)->setJSON([
                    'success' => false,
                    'data' => $resultat,
                    'message' => $resultat['message'] ?? 'Échec du test LDAP.',
                ]);
            }

            return $this->successResponse($resultat, 'Test LDAP terminé.');
        } catch (Throwable $e) {
            log_message('error', 'Test LDAP API impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de tester la connexion LDAP.', 500);
        }
    }

    public function ldapSyncUsers(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        $dryRun = filter_var($this->jsonInput()['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            $resultat = (new LdapAuthService())->syncUtilisateurs((int) $tenantId, $dryRun);
            return $this->successResponse($resultat, $dryRun ? 'Simulation LDAP terminée.' : 'Synchronisation LDAP terminée.');
        } catch (Throwable $e) {
            log_message('error', 'Synchronisation LDAP API impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de synchroniser les utilisateurs LDAP.', 502);
        }
    }
}