<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Résout le fuseau horaire effectif d'un tenant. */
class TenantTimezoneService
{
    private const DEFAULT_TIMEZONE = 'Africa/Bujumbura';

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function forTenant(int $tenantId): DateTimeZone
    {
        $row = $this->db->table('tenants')
            ->select('timezone')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        $timezone = trim((string) ($row['timezone'] ?? self::DEFAULT_TIMEZONE));

        try {
            return new DateTimeZone($timezone !== '' ? $timezone : self::DEFAULT_TIMEZONE);
        } catch (Throwable $exception) {
            log_message('error', 'Fuseau invalide pour le tenant #{tenant}: {timezone}', [
                'tenant' => $tenantId,
                'timezone' => $timezone,
            ]);

            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    public function now(int $tenantId): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->forTenant($tenantId));
    }
}
