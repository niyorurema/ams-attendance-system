<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Réglages d’intégrations applicatives, avec secrets chiffrés au repos.
 */
final class ApplicationIntegrationSettings
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function systemSettings(): array
    {
        $gpioDefaults = config('Database')->gpio;
        $smtpHost = $this->setting('smtp.host', (string) env('EMAIL_HOST', ''));
        $smtpEnabled = $this->setting('smtp.enabled', $smtpHost !== '' ? '1' : '0') === '1';

        return [
            'smtp' => [
                'enabled' => $smtpEnabled,
                'host' => $smtpHost,
                'port' => (int) $this->setting('smtp.port', (string) env('EMAIL_PORT', 25)),
                'encryption' => $this->setting('smtp.encryption', (string) env('EMAIL_ENCRYPTION', 'tls')),
                'username' => $this->setting('smtp.username', (string) env('EMAIL_USER', '')),
                'from_email' => $this->setting('smtp.from_email', (string) env('EMAIL_FROM', '')),
                'from_name' => $this->setting('smtp.from_name', (string) env('EMAIL_FROM_NAME', 'AMS')),
                'password_configured' => $this->secretConfigured('smtp.password', (string) env('EMAIL_PASS', '')),
                'legacy_configuration' => ! $this->hasStoredPrefix('smtp.'),
            ],
            'gpio' => [
                'sync_enabled' => $this->gpioSyncEnabled(),
                'hostname' => $this->setting('gpio.hostname', (string) ($gpioDefaults['hostname'] ?? '')),
                'database' => $this->setting('gpio.database', (string) ($gpioDefaults['database'] ?? '')),
                'username' => $this->setting('gpio.username', (string) ($gpioDefaults['username'] ?? '')),
                'port' => (int) $this->setting('gpio.port', (string) ($gpioDefaults['port'] ?? 3306)),
                'password_configured' => $this->secretConfigured('gpio.password', (string) ($gpioDefaults['password'] ?? '')),
                'legacy_configuration' => ! $this->hasStoredPrefix('gpio.'),
            ],
        ];
    }

    /** @return array<string, string|int|bool> */
    public function smtpConfiguration(): array
    {
        $settings = $this->systemSettings()['smtp'];

        return [
            'enabled' => (bool) $settings['enabled'],
            'host' => (string) $settings['host'],
            'port' => (int) $settings['port'],
            'encryption' => (string) $settings['encryption'],
            'username' => (string) $settings['username'],
            'password' => $this->setting('smtp.password', (string) env('EMAIL_PASS', '')),
            'from_email' => (string) $settings['from_email'],
            'from_name' => (string) $settings['from_name'],
        ];
    }

    public function gpioSyncEnabled(): bool
    {
        return filter_var(
            $this->setting('gpio.sync_enabled', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function gpioConnection(): BaseConnection
    {
        $base = config('Database')->gpio;
        $config = array_replace($base, [
            'hostname' => $this->setting('gpio.hostname', (string) ($base['hostname'] ?? '')),
            'database' => $this->setting('gpio.database', (string) ($base['database'] ?? '')),
            'username' => $this->setting('gpio.username', (string) ($base['username'] ?? '')),
            'password' => $this->setting('gpio.password', (string) ($base['password'] ?? '')),
            'port' => (int) $this->setting('gpio.port', (string) ($base['port'] ?? 3306)),
            'pConnect' => false,
        ]);

        if (trim((string) $config['hostname']) === '' || trim((string) $config['database']) === '' || trim((string) $config['username']) === '') {
            throw new RuntimeException('La connexion GPIO doit être configurée dans Paramètres > Intégrations.');
        }

        return Database::connect($config, false);
    }

    /** @param array<string, mixed> $input */
    public function saveSmtp(array $input, ?int $userId = null): void
    {
        $this->write('smtp.enabled', $input['enabled'] ? '1' : '0', false, $userId);
        $this->write('smtp.host', trim((string) $input['host']), false, $userId);
        $this->write('smtp.port', (string) (int) $input['port'], false, $userId);
        $this->write('smtp.encryption', (string) $input['encryption'], false, $userId);
        $this->write('smtp.username', trim((string) $input['username']), false, $userId);
        $this->write('smtp.from_email', strtolower(trim((string) $input['from_email'])), false, $userId);
        $this->write('smtp.from_name', trim((string) $input['from_name']), false, $userId);
        $this->saveSecret('smtp.password', (string) ($input['password'] ?? ''), (string) env('EMAIL_PASS', ''), $userId);
    }

    /** @param array<string, mixed> $input */
    public function saveGpio(array $input, ?int $userId = null): void
    {
        $this->write('gpio.sync_enabled', $input['sync_enabled'] ? '1' : '0', false, $userId);
        $this->write('gpio.hostname', trim((string) $input['hostname']), false, $userId);
        $this->write('gpio.database', trim((string) $input['database']), false, $userId);
        $this->write('gpio.username', trim((string) $input['username']), false, $userId);
        $this->write('gpio.port', (string) (int) $input['port'], false, $userId);
        $this->saveSecret('gpio.password', (string) ($input['password'] ?? ''), (string) (config('Database')->gpio['password'] ?? ''), $userId);
    }

    public function storeEncryptedSetting(string $key, string $value, ?int $userId = null): void
    {
        $this->write($key, $value, true, $userId);
    }

    public function decryptStoredSetting(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $binary = base64_decode($value, true);
        if ($binary === false || strlen($binary) < 30 || $binary[0] !== "\x01") {
            throw new RuntimeException('Un secret enregistré est illisible; vérifiez la clé de chiffrement des intégrations.');
        }

        $nonce = substr($binary, 1, 12);
        $tag = substr($binary, 13, 16);
        $ciphertext = substr($binary, 29);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(false), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Déchiffrement impossible; restaurez la clé stable des intégrations du serveur.');
        }

        return $plaintext;
    }

    public function encrypted(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Impossible de chiffrer le secret des intégrations.');
        }

        return base64_encode("\x01" . $nonce . $tag . $ciphertext);
    }

    private function setting(string $key, string $fallback): string
    {
        $row = $this->db->table('application_settings')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return $fallback;
        }

        $value = (string) ($row['setting_value'] ?? '');
        return (int) ($row['is_encrypted'] ?? 0) === 1 ? $this->decryptStoredSetting($value) : $value;
    }

    private function secretConfigured(string $key, string $legacy): bool
    {
        $row = $this->db->table('application_settings')
            ->select('setting_value')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();

        return ($row !== null && (string) ($row['setting_value'] ?? '') !== '') || $legacy !== '';
    }

    private function hasStoredPrefix(string $prefix): bool
    {
        return $this->db->table('application_settings')
            ->like('setting_key', $prefix, 'after')
            ->countAllResults() > 0;
    }

    private function saveSecret(string $key, string $submitted, string $legacyFallback, ?int $userId = null): void
    {
        $row = $this->db->table('application_settings')
            ->select('setting_key')
            ->where('setting_key', $key)
            ->get()
            ->getRowArray();
        if ($submitted !== '') {
            $this->write($key, $submitted, true, $userId);
        } elseif ($row === null && $legacyFallback !== '') {
            $this->write($key, $legacyFallback, true, $userId);
        }
    }

    private function write(string $key, string $value, bool $encrypt = false, ?int $userId = null): void
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'setting_key' => $key,
            'setting_value' => $encrypt ? $this->encrypted($value) : $value,
            'is_encrypted' => $encrypt ? 1 : 0,
            'updated_by' => $userId,
            'updated_at' => $now,
        ];
        $exists = $this->db->table('application_settings')->where('setting_key', $key)->countAllResults() > 0;
        if ($exists) {
            $saved = $this->db->table('application_settings')->where('setting_key', $key)->update($row);
        } else {
            $row['created_at'] = $now;
            $saved = $this->db->table('application_settings')->insert($row);
        }
        if (! $saved) {
            throw new RuntimeException('Impossible d’enregistrer un paramètre d’intégration.');
        }
    }

    private function encryptionKey(bool $create = true): string
    {
        $directory = WRITEPATH . 'keys';
        $path = $directory . DIRECTORY_SEPARATOR . 'integration-settings.key';
        if (is_file($path)) {
            $key = file_get_contents($path);
            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
            throw new RuntimeException('La clé de chiffrement des intégrations est invalide ou illisible.');
        }
        if (! $create) {
            throw new RuntimeException('La clé de chiffrement des intégrations manque. Restaurez sa sauvegarde avant de lire les secrets.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire protégé des clés d’intégration.');
        }

        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            if (is_file($path)) {
                return $this->encryptionKey(false);
            }
            throw new RuntimeException('Impossible de créer la clé de chiffrement des intégrations.');
        }
        $key = random_bytes(32);
        try {
            if (fwrite($handle, $key) !== strlen($key) || ! fflush($handle)) {
                throw new RuntimeException('Écriture incomplète de la clé de chiffrement des intégrations.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);

        return $key;
    }
}
