<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Exécute et valide les cérémonies WebAuthn pour les pointages personnels.
 */
final class WebAuthnPasskeyService
{
    private BaseConnection $db;
    private string $rpId;
    private string $origin;
    private \Symfony\Component\Serializer\SerializerInterface $serializer;
    private CeremonyStepManagerFactory $ceremonyFactory;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $baseUrl = (string) config('App')->baseURL;
        $this->origin = rtrim(trim((string) env('WEBAUTHN_ORIGIN', $baseUrl)), '/');
        $parsedOrigin = parse_url($this->origin);
        $originHost = is_array($parsedOrigin) ? strtolower((string) ($parsedOrigin['host'] ?? '')) : '';
        $this->rpId = strtolower(trim((string) env('WEBAUTHN_RP_ID', $originHost)));
        if ($originHost === '' || $this->rpId === '' || ! isset($parsedOrigin['scheme'])
            || isset($parsedOrigin['user']) || isset($parsedOrigin['pass'])
            || isset($parsedOrigin['query']) || isset($parsedOrigin['fragment'])
            || (isset($parsedOrigin['path']) && $parsedOrigin['path'] !== '' && $parsedOrigin['path'] !== '/')
        ) {
            throw new RuntimeException('Configurez WEBAUTHN_ORIGIN et WEBAUTHN_RP_ID pour activer les passkeys.');
        }
        if ($originHost !== $this->rpId && ! str_ends_with($originHost, '.' . $this->rpId)) {
            throw new RuntimeException('Le RP ID WebAuthn doit correspondre au domaine de l’origine configurée.');
        }
        if ($parsedOrigin['scheme'] !== 'https' && ! in_array($originHost, ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('WebAuthn exige HTTPS, sauf en développement local.');
        }

        $attestationManager = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();
        $this->ceremonyFactory = new CeremonyStepManagerFactory();
        $this->ceremonyFactory->setAllowedOrigins([$this->origin]);
        $this->ceremonyFactory->setSecuredRelyingPartyId([$this->rpId]);
    }

    /**
     * @param array{id:int,email:string,nom_complet:?string} $user
     * @return array{challenge_id:string,public_key:array<string,mixed>}
     */
    public function registrationOptions(int $tenantId, array $user): array
    {
        $userId = (int) $user['id'];
        if ((int) ($user['webauthn_enrollment_approved'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Un administrateur doit d’abord autoriser votre enrôlement de passkey après vérification de votre identité.');
        }
        $challenge = random_bytes(32);
        $credentials = $this->credentialsFor($tenantId, $userId);
        $exclude = array_map(
            static fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
            $credentials
        );
        $name = trim((string) ($user['email'] ?? ''));
        $displayName = trim((string) ($user['nom_complet'] ?? ''));
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('AMS', $this->rpId),
            PublicKeyCredentialUserEntity::create(
                $name !== '' ? $name : 'user-' . $userId,
                random_bytes(32),
                $displayName !== '' ? $displayName : ($name !== '' ? $name : 'Utilisateur AMS')
            ),
            $challenge,
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create('platform', 'required', 'preferred'),
            'none',
            $exclude,
            60000
        );

        return $this->storeOptions($tenantId, $userId, 'registration', $options);
    }

    /**
     * @return array{challenge_id:string,public_key:array<string,mixed>}
     */
    public function authenticationOptions(int $tenantId, int $userId): array
    {
        $credentials = $this->credentialsFor($tenantId, $userId);
        if ($credentials === []) {
            throw new InvalidArgumentException('Enrôlez d’abord une passkey sur cet appareil.');
        }
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId,
            array_map(
                static fn (CredentialRecord $record): PublicKeyCredentialDescriptor => $record->getPublicKeyCredentialDescriptor(),
                $credentials
            ),
            'required',
            60000
        );

        return $this->storeOptions($tenantId, $userId, 'authentication', $options);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function registerCredential(int $tenantId, int $userId, string $challengeId, array $payload): void
    {
        $optionsJson = $this->consumeChallenge($tenantId, $userId, $challengeId, 'registration');
        $transactionOuverte = false;
        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->deserializeCredential($payload);
            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                throw new InvalidArgumentException('La réponse WebAuthn reçue n’est pas une inscription.');
            }
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory->creationCeremony())
                ->check($credential->response, $options, $this->rpId);
            $hash = hash('sha256', $record->publicKeyCredentialId);
            $this->db->transBegin();
            $transactionOuverte = true;
            $this->db->table('users')
                ->where('id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('webauthn_enrollment_approved', 1)
                ->update(['webauthn_enrollment_approved' => 0]);
            if ($this->db->affectedRows() !== 1) {
                throw new InvalidArgumentException('L’autorisation d’enrôlement a expiré ou a déjà été utilisée.');
            }
            $this->db->table('webauthn_credentials')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'credential_hash' => $hash,
                'credential_data' => $this->serializer->serialize($record, 'json'),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Échec de la transaction d’enrôlement.');
            }
            $this->db->transCommit();
            $transactionOuverte = false;
        } catch (InvalidArgumentException $exception) {
            if ($transactionOuverte) {
                $this->db->transRollback();
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($transactionOuverte) {
                $this->db->transRollback();
            }
            log_message('warning', 'Échec de vérification de l’enrôlement WebAuthn pour le compte #{user}: {error}', [
                'user' => $userId,
                'error' => $exception->getMessage(),
            ]);
            throw new InvalidArgumentException('La vérification de la passkey a échoué.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return string Empreinte du credential vérifié, à enregistrer dans l’audit.
     */
    public function verifyAssertion(int $tenantId, int $userId, string $challengeId, array $payload): string
    {
        $optionsJson = $this->consumeChallenge($tenantId, $userId, $challengeId, 'authentication');
        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->deserializeCredential($payload);
            if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                throw new InvalidArgumentException('La réponse WebAuthn reçue n’est pas une authentification.');
            }
            $hash = hash('sha256', $credential->rawId);
            $row = $this->db->table('webauthn_credentials')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('credential_hash', $hash)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Cette passkey n’est pas enregistrée pour votre compte.');
            }
            $record = $this->serializer->deserialize($row['credential_data'], CredentialRecord::class, 'json');
            if (! hash_equals($record->publicKeyCredentialId, $credential->rawId)) {
                throw new InvalidArgumentException('Identifiant de passkey invalide.');
            }
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory->requestCeremony())
                ->check($record, $credential->response, $options, $this->rpId, $credential->response->userHandle);
            $this->db->table('webauthn_credentials')
                ->where('id', (int) $row['id'])
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->update([
                    'credential_data' => $this->serializer->serialize($record, 'json'),
                    'last_used_at' => gmdate('Y-m-d H:i:s'),
                ]);

            return $hash;
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            log_message('warning', 'Échec de vérification d’une assertion WebAuthn pour le compte #{user}: {error}', [
                'user' => $userId,
                'error' => $exception->getMessage(),
            ]);
            throw new InvalidArgumentException('La vérification de la passkey a échoué.');
        }
    }

    /** @return array{challenge_id:string,public_key:array<string,mixed>} */
    private function storeOptions(int $tenantId, int $userId, string $purpose, object $options): array
    {
        $json = $this->serializer->serialize($options, 'json');
        $id = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->db->table('webauthn_challenges')
            ->where('expires_at <', $now->modify('-1 day')->format('Y-m-d H:i:s'))
            ->delete();
        $this->db->table('webauthn_challenges')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'purpose' => $purpose,
            'options_json' => $json,
            'expires_at' => $now->modify('+5 minutes')->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $publicKey = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($publicKey)) {
            throw new RuntimeException('Options WebAuthn invalides.');
        }

        return ['challenge_id' => $id, 'public_key' => $publicKey];
    }

    private function consumeChallenge(int $tenantId, int $userId, string $id, string $purpose): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
            throw new InvalidArgumentException('Challenge WebAuthn invalide ou expiré.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = $this->db->table('webauthn_challenges')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->where('expires_at >', $now)
            ->get()
            ->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Challenge WebAuthn invalide ou expiré.');
        }
        $this->db->table('webauthn_challenges')
            ->where('id', $id)
            ->where('consumed_at', null)
            ->update(['consumed_at' => $now]);
        if ($this->db->affectedRows() !== 1) {
            throw new InvalidArgumentException('Ce challenge WebAuthn a déjà été utilisé.');
        }

        return (string) $row['options_json'];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function deserializeCredential(array $payload): PublicKeyCredential
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $credential = $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (JsonException|\Symfony\Component\Serializer\Exception\ExceptionInterface $exception) {
            throw new InvalidArgumentException('Réponse WebAuthn mal formée.', 0, $exception);
        }
        if (! $credential instanceof PublicKeyCredential) {
            throw new InvalidArgumentException('Réponse WebAuthn mal formée.');
        }

        return $credential;
    }

    /** @return CredentialRecord[] */
    private function credentialsFor(int $tenantId, int $userId): array
    {
        $rows = $this->db->table('webauthn_credentials')
            ->select('credential_data')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->getResultArray();
        $credentials = [];
        foreach ($rows as $row) {
            $record = $this->serializer->deserialize($row['credential_data'], CredentialRecord::class, 'json');
            if (! $record instanceof CredentialRecord) {
                throw new RuntimeException('Enregistrement de passkey invalide.');
            }
            $credentials[] = $record;
        }

        return $credentials;
    }
}
