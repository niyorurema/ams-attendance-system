<?php

namespace App\Controllers\Api;

use App\Services\ApplicationIntegrationSettings;
use App\Services\LdapEmployeeEmailSyncService;
use App\Services\LdapAuthService;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * Paramètres des intégrations système et LDAP du tenant courant.
 */
final class IntegrationSettingsController extends BaseApiController
{
    public function system(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette configuration est réservée au super-administrateur.', 403);
        }

        try {
            return $this->successResponse((new ApplicationIntegrationSettings())->systemSettings());
        } catch (Throwable $exception) {
            log_message('error', 'Lecture des intégrations système impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Impossible de charger les intégrations système.', 500);
        }
    }

    public function updateSystem(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette configuration est réservée au super-administrateur.', 403);
        }

        $input = $this->jsonInput();
        if (count($input) !== 1 || (! isset($input['smtp']) && ! isset($input['gpio'])) || ! is_array(reset($input))) {
            return $this->errorResponse('Envoyez les paramètres SMTP ou GPIO à mettre à jour.', 400);
        }

        $settings = new ApplicationIntegrationSettings();
        $db = Database::connect();
        $db->transBegin();
        try {
            if (isset($input['smtp'])) {
                $validation = $this->validateSmtp($input['smtp']);
                if ($validation !== null) {
                    $db->transRollback();
                    return $validation;
                }
                $settings->saveSmtp($input['smtp'], (int) $this->claims()->sub);
                $smtp = $settings->smtpConfiguration();
                if ($smtp['enabled']
                    && (trim((string) $smtp['host']) === ''
                        || ! filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)
                        || ((string) $smtp['username'] !== '' && (string) $smtp['password'] === ''))
                ) {
                    $db->transRollback();
                    return $this->errorResponse('Un serveur, une adresse d’expédition valide et le mot de passe d’authentification SMTP sont requis avant l’activation.', 422);
                }
            } else {
                $validation = $this->validateGpio($input['gpio']);
                if ($validation !== null) {
                    $db->transRollback();
                    return $validation;
                }
                if ($input['gpio']['sync_enabled']
                    && (trim($input['gpio']['hostname']) === ''
                        || trim($input['gpio']['database']) === ''
                        || trim($input['gpio']['username']) === '')
                ) {
                    $db->transRollback();
                    return $this->errorResponse('Renseignez l’hôte, la base et l’utilisateur GPIO avant d’activer la synchronisation.', 422);
                }
                $settings->saveGpio($input['gpio'], (int) $this->claims()->sub);
            }

            if ($db->transStatus() === false) {
                throw new RuntimeException('Transaction de configuration des intégrations invalide.');
            }
            $db->transCommit();
            return $this->successResponse($settings->systemSettings(), 'Paramètres d’intégration enregistrés.');
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Enregistrement des intégrations système impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Impossible d’enregistrer les paramètres. Vérifiez la clé de chiffrement et réessayez.', 500);
        }
    }

    public function testSmtp(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette configuration est réservée au super-administrateur.', 403);
        }

        try {
            $configuration = (new ApplicationIntegrationSettings())->smtpConfiguration();
            if (! $configuration['enabled'] || trim((string) $configuration['host']) === '') {
                return $this->errorResponse('Enregistrez et activez le serveur SMTP avant le test.', 422);
            }
            if (! filter_var($configuration['from_email'], FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse('Configurez une adresse d’expédition valide avant le test SMTP.', 422);
            }

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) $configuration['host'];
            $mail->Port = (int) $configuration['port'];
            $mail->SMTPAuth = (string) $configuration['username'] !== '';
            $mail->Username = (string) $configuration['username'];
            $mail->Password = (string) $configuration['password'];
            $mail->Timeout = 8;
            $mail->SMTPDebug = 0;
            $mail->SMTPSecure = match ($configuration['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            if (! $mail->smtpConnect()) {
                throw new RuntimeException('Le serveur SMTP a refusé la connexion.');
            }
            $mail->smtpClose();

            return $this->successResponse(['success' => true], 'Connexion au serveur SMTP réussie; aucun e-mail n’a été envoyé.');
        } catch (Throwable $exception) {
            log_message('error', 'Test SMTP impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Échec du test SMTP. Vérifiez l’hôte, le port, le chiffrement et les identifiants.', 502);
        }
    }

    public function testGpio(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette configuration est réservée au super-administrateur.', 403);
        }

        try {
            $gpio = (new ApplicationIntegrationSettings())->gpioConnection();
            $result = $gpio->query('SELECT 1 AS connection_ok')->getRowArray();
            $gpio->close();
            if ((int) ($result['connection_ok'] ?? 0) !== 1) {
                throw new RuntimeException('Réponse inattendue de la base source.');
            }

            return $this->successResponse(['success' => true], 'Connexion à la base ETau réussie; aucune donnée n’a été modifiée.');
        } catch (Throwable $exception) {
            log_message('error', 'Test de connexion GPIO impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Échec de connexion à la base GPIO. Vérifiez les paramètres et les droits en lecture.', 502);
        }
    }

    public function ldap(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $tenant = Database::connect()->table('tenants')
            ->select('auth_mode, ldap_host, ldap_host_secondary, ldap_host_tertiary, ldap_port, ldap_base_dn, ldap_bind_dn, ldap_bind_password, ldap_bind_password_encrypted, ldap_user_filter, ldap_use_tls, ldap_attr_email, ldap_attr_nom, ldap_attr_prenom, ldap_attr_matricule')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        $tenantHasLdapHosts = array_filter([
            $tenant['ldap_host'] ?? null,
            $tenant['ldap_host_secondary'] ?? null,
            $tenant['ldap_host_tertiary'] ?? null,
        ], static fn ($host): bool => is_string($host) && trim($host) !== '') !== [];
        $ldapConfiguration = [
            'auth_mode' => (string) $tenant['auth_mode'],
            'ldap_host' => (string) ($tenantHasLdapHosts
                ? ($tenant['ldap_host'] ?? '')
                : ($tenant['ldap_host'] ?: env('LDAP_HOST_PRIMARY', ''))),
            'ldap_host_secondary' => (string) ($tenantHasLdapHosts
                ? ($tenant['ldap_host_secondary'] ?? '')
                : ($tenant['ldap_host_secondary'] ?: env('LDAP_HOST_SECONDARY', ''))),
            'ldap_host_tertiary' => (string) ($tenantHasLdapHosts
                ? ($tenant['ldap_host_tertiary'] ?? '')
                : ($tenant['ldap_host_tertiary'] ?: env('LDAP_HOST_TERTIARY', ''))),
            'ldap_port' => (int) (! $tenantHasLdapHosts && (int) $tenant['ldap_port'] === 389
                ? env('LDAP_PORT', 389)
                : $tenant['ldap_port']),
            'ldap_base_dn' => (string) ($tenant['ldap_base_dn'] ?: env('LDAP_USER_BASE_DN', env('LDAP_BASE_DN', ''))),
            'ldap_bind_dn' => (string) ($tenant['ldap_bind_dn'] ?: env('LDAP_BIND_DN', '')),
            'bind_password_configured' => (string) ($tenant['ldap_bind_password_encrypted'] ?? '') !== ''
                || (string) ($tenant['ldap_bind_password'] ?? '') !== ''
                || (string) env('LDAP_BIND_PASSWORD', '') !== '',
            'ldap_user_filter' => (string) ($tenant['ldap_user_filter'] ?: env('LDAP_USER_FILTER', '(sAMAccountName={username})')),
            'ldap_use_tls' => $tenantHasLdapHosts
                ? (bool) $tenant['ldap_use_tls']
                : ((bool) $tenant['ldap_use_tls'] || filter_var(env('LDAP_USE_TLS', false), FILTER_VALIDATE_BOOLEAN)),
            'ldap_attr_email' => (string) ($tenant['ldap_attr_email'] ?: env('LDAP_ATTR_EMAIL', 'mail')),
            'ldap_attr_nom' => (string) ($tenant['ldap_attr_nom'] ?: env('LDAP_ATTR_NOM', 'sn')),
            'ldap_attr_prenom' => (string) ($tenant['ldap_attr_prenom'] ?: env('LDAP_ATTR_PRENOM', 'givenName')),
            'ldap_attr_matricule' => (string) ($tenant['ldap_attr_matricule'] ?: env('LDAP_ATTR_MATRICULE', 'employeeID')),
            'legacy_configuration' => ! $tenantHasLdapHosts && trim((string) env('LDAP_HOST_PRIMARY', '')) !== '',
        ];
        return $this->successResponse($ldapConfiguration);
    }

    public function updateLdap(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }
        if (! $this->isSuperAdmin() && (string) $claims->role !== 'admin') {
            return $this->errorResponse('Seuls les administrateurs peuvent modifier les paramètres LDAP.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        $input = $this->jsonInput();
        $allowed = [
            'auth_mode', 'ldap_host', 'ldap_host_secondary', 'ldap_host_tertiary', 'ldap_port',
            'ldap_base_dn', 'ldap_bind_dn', 'ldap_bind_password', 'ldap_user_filter', 'ldap_use_tls',
            'ldap_attr_email', 'ldap_attr_nom', 'ldap_attr_prenom', 'ldap_attr_matricule',
        ];
        if ($input === [] || array_diff(array_keys($input), $allowed) !== []) {
            return $this->errorResponse('Configuration LDAP invalide ou vide.', 400);
        }
        $validation = $this->validateLdap($input);
        if ($validation !== null) {
            return $validation;
        }

        $db = Database::connect();
        $tenant = $db->table('tenants')
            ->select('auth_mode, ldap_host, ldap_host_secondary, ldap_host_tertiary, ldap_base_dn, ldap_bind_dn, ldap_bind_password, ldap_bind_password_encrypted')
            ->where('id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($tenant === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        $tenantHasLdapHosts = array_filter([
            $tenant['ldap_host'] ?? null,
            $tenant['ldap_host_secondary'] ?? null,
            $tenant['ldap_host_tertiary'] ?? null,
        ], static fn ($host): bool => is_string($host) && trim($host) !== '') !== [];
        $updates = [];
        foreach (array_diff($allowed, ['ldap_bind_password']) as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        $password = (string) ($input['ldap_bind_password'] ?? '');
        $legacyPassword = (string) ($tenant['ldap_bind_password'] ?? '');
        if ($legacyPassword === '' && empty($tenant['ldap_bind_password_encrypted'])) {
            $legacyPassword = (string) env('LDAP_BIND_PASSWORD', '');
        }
        foreach ([
            'ldap_host' => 'LDAP_HOST_PRIMARY',
            'ldap_host_secondary' => 'LDAP_HOST_SECONDARY',
            'ldap_host_tertiary' => 'LDAP_HOST_TERTIARY',
            'ldap_base_dn' => 'LDAP_USER_BASE_DN',
            'ldap_bind_dn' => 'LDAP_BIND_DN',
            'ldap_user_filter' => 'LDAP_USER_FILTER',
            'ldap_attr_email' => 'LDAP_ATTR_EMAIL',
            'ldap_attr_nom' => 'LDAP_ATTR_NOM',
            'ldap_attr_prenom' => 'LDAP_ATTR_PRENOM',
            'ldap_attr_matricule' => 'LDAP_ATTR_MATRICULE',
        ] as $field => $environmentKey) {
            if (! $tenantHasLdapHosts
                && array_key_exists($field, $input)
                && is_string($input[$field])
                && trim($input[$field]) === ''
            ) {
                $fallback = (string) env($environmentKey, '');
                if ($field === 'ldap_base_dn' && $fallback === '') {
                    $fallback = (string) env('LDAP_BASE_DN', '');
                }
                if ($field === 'ldap_user_filter' && $fallback === '') {
                    $fallback = '(sAMAccountName={username})';
                }
                $input[$field] = $fallback;
            }
        }
        $primaryHost = trim((string) ($input['ldap_host'] ?? $tenant['ldap_host'] ?? ''));
        if (! $tenantHasLdapHosts && $primaryHost === '') {
            if (isset($input['ldap_port']) && (int) $input['ldap_port'] === 389) {
                $input['ldap_port'] = (int) env('LDAP_PORT', 389);
            }
            if (isset($input['ldap_use_tls']) && ! $input['ldap_use_tls']) {
                $input['ldap_use_tls'] = filter_var(env('LDAP_USE_TLS', false), FILTER_VALIDATE_BOOLEAN);
            }
        }

        $authMode = (string) ($input['auth_mode'] ?? $tenant['auth_mode']);
        if (in_array($authMode, ['LDAP', 'HYBRIDE'], true)) {
            $hosts = [
                $input['ldap_host'] ?? $tenant['ldap_host'] ?? env('LDAP_HOST_PRIMARY', ''),
                $input['ldap_host_secondary'] ?? $tenant['ldap_host_secondary'] ?? env('LDAP_HOST_SECONDARY', ''),
                $input['ldap_host_tertiary'] ?? $tenant['ldap_host_tertiary'] ?? env('LDAP_HOST_TERTIARY', ''),
            ];
            $baseDn = trim((string) ($input['ldap_base_dn'] ?? $tenant['ldap_base_dn'] ?? env('LDAP_USER_BASE_DN', env('LDAP_BASE_DN', ''))));
            $bindDn = trim((string) ($input['ldap_bind_dn'] ?? $tenant['ldap_bind_dn'] ?? env('LDAP_BIND_DN', '')));
            if (! array_filter($hosts, static fn ($host): bool => is_string($host) && trim($host) !== '')
                || $baseDn === ''
                || $bindDn === ''
                || ($password === '' && $legacyPassword === '' && empty($tenant['ldap_bind_password_encrypted']))
            ) {
                return $this->errorResponse('Pour activer LDAP, renseignez au moins un serveur, une base DN et les identifiants du compte de service.', 422);
            }
        }

        if ($password !== '' || $legacyPassword !== '') {
            try {
                $plainPassword = $password !== ''
                    ? $password
                    : ((new ApplicationIntegrationSettings())->decryptStoredSetting(
                        $tenant['ldap_bind_password_encrypted'] ?? null
                    ) ?: $legacyPassword);
                $updates['ldap_bind_password_encrypted'] = (new ApplicationIntegrationSettings())->encrypted($plainPassword);
                $updates['ldap_bind_password'] = null;
            } catch (Throwable $exception) {
                log_message('error', 'Chiffrement du secret LDAP impossible : {error}', ['error' => $exception->getMessage()]);
                return $this->errorResponse('Impossible de protéger le secret LDAP. Vérifiez la clé de chiffrement serveur.', 500);
            }
        }
        if ($updates === []) {
            return $this->errorResponse('Aucun paramètre LDAP modifiable fourni.', 400);
        }
        $updates['ldap_statut'] = 'NON_TESTE';
        $updates['ldap_derniere_verif'] = null;
        $updates['updated_at'] = date('Y-m-d H:i:s');
        if (! $db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->update($updates)) {
            return $this->errorResponse('Impossible d’enregistrer la configuration LDAP.', 500);
        }

        return $this->ldap();
    }

    public function previewEmployeeEmails(): ResponseInterface
    {
        return $this->syncEmployeeEmails(false);
    }

    public function applyEmployeeEmails(): ResponseInterface
    {
        $input = $this->jsonInput();
        if (($input['confirm'] ?? null) !== true || count($input) !== 1) {
            return $this->errorResponse('Confirmez explicitement la mise à jour des adresses email.', 400);
        }

        return $this->syncEmployeeEmails(true);
    }

    private function syncEmployeeEmails(bool $apply): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            $result = $apply
                ? (new LdapEmployeeEmailSyncService())->apply($tenantId)
                : (new LdapEmployeeEmailSyncService())->preview($tenantId);

            return $this->successResponse(
                $result,
                $apply
                    ? 'Synchronisation des adresses email terminée.'
                    : 'Prévisualisation LDAP terminée. Aucune donnée n’a été modifiée.'
            );
        } catch (Throwable $exception) {
            log_message('error', 'Synchronisation des emails employés via LDAP impossible : {error}', [
                'error' => $exception->getMessage(),
                'tenant_id' => $tenantId,
                'apply' => $apply,
            ]);

            return $this->errorResponse(
                'La recherche LDAP ou la synchronisation a échoué. Aucune modification partielle n’a été conservée; vérifiez la configuration LDAP et réessayez.',
                502
            );
        }
    }

    private function validateSmtp(array $input): ?ResponseInterface
    {
        $allowed = ['enabled', 'host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name'];
        if ($input === [] || array_diff(array_keys($input), $allowed) !== []) {
            return $this->errorResponse('Paramètres SMTP inconnus ou configuration vide.', 422);
        }
        if (! isset($input['enabled']) || ! in_array($input['enabled'], [true, false, 0, 1, '0', '1'], true)) {
            return $this->errorResponse('Le statut SMTP doit être booléen.', 422);
        }
        foreach (['host' => 255, 'username' => 255, 'from_name' => 150] as $field => $max) {
            if (! isset($input[$field]) || ! is_string($input[$field]) || mb_strlen(trim($input[$field])) > $max) {
                return $this->errorResponse('Paramètres SMTP invalides.', 422);
            }
        }
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            return $this->errorResponse('Port SMTP invalide.', 422);
        }
        if (! in_array($input['encryption'] ?? null, ['none', 'tls', 'ssl'], true)) {
            return $this->errorResponse('Chiffrement SMTP invalide.', 422);
        }
        if (! is_string($input['from_email'] ?? null)
            || mb_strlen($input['from_email']) > 150
            || ($input['from_email'] !== '' && filter_var($input['from_email'], FILTER_VALIDATE_EMAIL) === false)
        ) {
            return $this->errorResponse('Adresse d’expédition SMTP invalide.', 422);
        }
        if (isset($input['password']) && (! is_string($input['password']) || strlen($input['password']) > 4096)) {
            return $this->errorResponse('Mot de passe SMTP invalide.', 422);
        }

        return null;
    }

    private function validateGpio(array $input): ?ResponseInterface
    {
        $allowed = ['sync_enabled', 'hostname', 'database', 'username', 'password', 'port'];
        if ($input === [] || array_diff(array_keys($input), $allowed) !== []) {
            return $this->errorResponse('Paramètres GPIO inconnus ou configuration vide.', 422);
        }
        if (! isset($input['sync_enabled']) || ! in_array($input['sync_enabled'], [true, false, 0, 1, '0', '1'], true)) {
            return $this->errorResponse('Le statut de synchronisation GPIO doit être booléen.', 422);
        }
        foreach (['hostname' => 255, 'database' => 64, 'username' => 100] as $field => $max) {
            if (! isset($input[$field]) || ! is_string($input[$field]) || mb_strlen(trim($input[$field])) > $max) {
                return $this->errorResponse('Paramètres de connexion GPIO invalides.', 422);
            }
        }
        $port = filter_var($input['port'] ?? null, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            return $this->errorResponse('Port GPIO invalide.', 422);
        }
        if (isset($input['password']) && (! is_string($input['password']) || strlen($input['password']) > 4096)) {
            return $this->errorResponse('Mot de passe GPIO invalide.', 422);
        }

        return null;
    }

    private function validateLdap(array $input): ?ResponseInterface
    {
        if (array_key_exists('auth_mode', $input) && ! in_array($input['auth_mode'], ['LOCAL', 'LDAP', 'HYBRIDE'], true)) {
            return $this->errorResponse('Mode d’authentification invalide.', 422);
        }
        if (array_key_exists('ldap_port', $input)) {
            $port = filter_var($input['ldap_port'], FILTER_VALIDATE_INT);
            if ($port === false || $port < 1 || $port > 65535) {
                return $this->errorResponse('Port LDAP invalide.', 422);
            }
        }
        foreach (['ldap_host' => 255, 'ldap_host_secondary' => 255, 'ldap_host_tertiary' => 255, 'ldap_base_dn' => 255, 'ldap_bind_dn' => 255, 'ldap_user_filter' => 255] as $field => $max) {
            if (array_key_exists($field, $input) && (! is_string($input[$field]) || mb_strlen(trim($input[$field])) > $max)) {
                return $this->errorResponse('Paramètres LDAP invalides.', 422);
            }
        }
        if (array_key_exists('ldap_use_tls', $input) && ! in_array($input['ldap_use_tls'], [true, false, 0, 1, '0', '1'], true)) {
            return $this->errorResponse('Le paramètre TLS LDAP doit être booléen.', 422);
        }
        foreach (['ldap_attr_email', 'ldap_attr_nom', 'ldap_attr_prenom', 'ldap_attr_matricule'] as $field) {
            if (array_key_exists($field, $input)
                && (! is_string($input[$field]) || preg_match('/^[A-Za-z][A-Za-z0-9-]{0,49}$/', $input[$field]) !== 1)
            ) {
                return $this->errorResponse('Attribut LDAP invalide.', 422);
            }
        }
        if (array_key_exists('ldap_user_filter', $input)
            && (! is_string($input['ldap_user_filter'])
                || ! str_contains($input['ldap_user_filter'], '{username}')
                || preg_match('/[\r\n]/', $input['ldap_user_filter']) === 1)
        ) {
            return $this->errorResponse('Le filtre LDAP doit contenir {username} et ne pas contenir de saut de ligne.', 422);
        }
        if (array_key_exists('ldap_bind_password', $input)
            && (! is_string($input['ldap_bind_password']) || strlen($input['ldap_bind_password']) > 4096)
        ) {
            return $this->errorResponse('Mot de passe LDAP invalide.', 422);
        }

        return null;
    }
}
