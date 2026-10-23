<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use UnexpectedValueException;

/**
 * Émet et valide les jetons JWT de l’API.
 */
class JwtTokenService
{
    private const ISSUER = 'ams';
    private const ALGORITHM = 'HS256';

    public function isConfigured(): bool
    {
        return strlen(trim((string) env('JWT_SECRET', ''))) >= 32;
    }

    /**
     * Crée un jeton pour un utilisateur applicatif.
     *
     * @return array{access_token:string,expires_in:int}
     */
    public function issueForUser(array $user): array
    {
        $secret = $this->secret();
        $ttl = max(60, min(86400, (int) env('JWT_TTL', 3600)));
        $now = time();

        $payload = [
            'iss' => self::ISSUER,
            'sub' => (string) $user['id'],
            'tenant_id' => (int) $user['tenant_id'],
            'role' => (string) $user['role'],
            'is_super_admin' => (bool) $user['is_super_admin'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ];

        return [
            'access_token' => JWT::encode($payload, $secret, self::ALGORITHM),
            'expires_in' => $ttl,
        ];
    }

    /**
     * Valide la signature et les claims obligatoires d’un jeton.
     */
    public function decodeToken(string $token): \stdClass
    {
        $claims = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));

        if (($claims->iss ?? null) !== self::ISSUER
            || ! isset($claims->sub, $claims->tenant_id, $claims->is_super_admin, $claims->exp)
            || ! ctype_digit((string) $claims->sub)
            || (int) $claims->tenant_id < 1
        ) {
            throw new UnexpectedValueException('Claims JWT invalides.');
        }

        return $claims;
    }

    private function secret(): string
    {
        $secret = trim((string) env('JWT_SECRET', ''));

        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET doit contenir au moins 32 caractères.');
        }

        return $secret;
    }
}