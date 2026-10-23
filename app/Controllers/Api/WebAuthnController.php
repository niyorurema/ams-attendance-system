<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\WebAuthnPasskeyService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

final class WebAuthnController extends BaseApiController
{
    public function registrationOptions(): ResponseInterface
    {
        $context = $this->authenticatedUser();
        if ($context instanceof ResponseInterface) {
            return $context;
        }
        [$tenantId, $user] = $context;
        if (empty($user['employe_id'])) {
            return $this->errorResponse('Un dossier employé doit être associé à votre compte.', 422);
        }
        try {
            return $this->successResponse((new WebAuthnPasskeyService())->registrationOptions($tenantId, $user));
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            log_message('error', 'Création des options d’enrôlement WebAuthn impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Le service de passkey n’est pas correctement configuré.', 503);
        }
    }

    public function authenticationOptions(): ResponseInterface
    {
        $context = $this->authenticatedUser();
        if ($context instanceof ResponseInterface) {
            return $context;
        }
        [$tenantId, $user] = $context;
        if (empty($user['employe_id'])) {
            return $this->errorResponse('Un dossier employé doit être associé à votre compte.', 422);
        }

        try {
            return $this->successResponse(
                (new WebAuthnPasskeyService())->authenticationOptions($tenantId, (int) $user['id'])
            );
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Création des options d’authentification WebAuthn impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Le service de passkey n’est pas correctement configuré.', 503);
        }
    }

    public function register(): ResponseInterface
    {
        $context = $this->authenticatedUser();
        if ($context instanceof ResponseInterface) {
            return $context;
        }
        [$tenantId, $user] = $context;
        if (empty($user['employe_id'])) {
            return $this->errorResponse('Un dossier employé doit être associé à votre compte.', 422);
        }
        $data = $this->jsonInput();
        $challengeId = trim((string) ($data['challenge_id'] ?? ''));
        if (! is_array($data['credential'] ?? null)) {
            return $this->errorResponse('Réponse WebAuthn manquante.', 422);
        }

        try {
            (new WebAuthnPasskeyService())->registerCredential(
                $tenantId,
                (int) $user['id'],
                $challengeId,
                $data['credential']
            );
            return $this->successResponse(['registered' => true], 'Passkey enregistrée.');
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 401);
        } catch (Throwable $exception) {
            log_message('error', 'Enrôlement WebAuthn impossible pour le compte #{user}: {error}', [
                'user' => (int) $user['id'],
                'error' => $exception->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’enregistrer cette passkey.', 500);
        }
    }

    /**
     * @return array{int,array<string,mixed>}|ResponseInterface
     */
    private function authenticatedUser(): array|ResponseInterface
    {
        $claims = $this->claims();
        $tenantId = $this->tenantId();
        if ($claims === null || $tenantId === null) {
            return $this->authorizationError();
        }
        $user = (new UserModel())
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->find((int) $claims->sub);
        if ($user === null) {
            return $this->errorResponse('Utilisateur introuvable pour ce tenant.', 401);
        }

        return [$tenantId, $user];
    }
}
