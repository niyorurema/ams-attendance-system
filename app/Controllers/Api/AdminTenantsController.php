<?php

namespace App\Controllers\Api;

use App\Models\TenantModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeZone;
use Throwable;

/** Administration globale des tenants, réservée aux super-administrateurs. */
class AdminTenantsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if (! $this->canAdministerTenants()) {
            return $this->adminOnlyResponse();
        }

        $db = Database::connect();
        $builder = $db->table('tenants t')
            ->select('t.id, t.name, t.slug, t.contact_email, t.timezone, t.is_active, t.created_at, t.deleted_at')
            ->select('(SELECT COUNT(*) FROM employes e WHERE e.tenant_id = t.id AND e.deleted_at IS NULL) AS employee_count', false)
            ->select('(SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count', false);

        $search = trim((string) ($this->request->getGet('search') ?? ''));
        if ($search !== '') {
            $builder->groupStart()
                ->like('t.name', $search)
                ->orLike('t.slug', $search)
                ->orLike('t.contact_email', $search)
                ->groupEnd();
        }

        $active = $this->request->getGet('actif');
        if ($active !== null && $active !== '') {
            if (! in_array((string) $active, ['0', '1'], true)) {
                return $this->errorResponse('Le filtre actif doit valoir 0 ou 1.', 422);
            }
            $builder->where('t.is_active', (int) $active);
        }

        $limit = $this->boundedInteger($this->request->getGet('limit'), 10, 1, 100);
        $offset = $this->boundedInteger($this->request->getGet('offset'), 0, 0, 1000000);
        $total = (clone $builder)->countAllResults();
        $items = $builder->orderBy('t.created_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        foreach ($items as &$tenant) {
            $tenant['id'] = (int) $tenant['id'];
            $tenant['is_active'] = (bool) $tenant['is_active'];
            $tenant['employee_count'] = (int) $tenant['employee_count'];
            $tenant['user_count'] = (int) $tenant['user_count'];
        }
        unset($tenant);

        return $this->successResponse([
            'items' => $items,
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'total' => $total],
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->canAdministerTenants()) {
            return $this->adminOnlyResponse();
        }

        $tenant = $this->findTenant($id);
        if ($tenant === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        return $this->successResponse($tenant);
    }

    public function create(): ResponseInterface
    {
        if (! $this->canAdministerTenants()) {
            return $this->adminOnlyResponse();
        }

        $input = $this->jsonInput();
        $name = trim((string) ($input['name'] ?? ''));
        $slug = $this->normalizeSlug((string) ($input['slug'] ?? ''), $name);
        $contactEmail = strtolower(trim((string) ($input['contact_email'] ?? '')));
        $timezone = trim((string) ($input['timezone'] ?? 'Africa/Bujumbura'));
        $adminName = trim((string) ($input['admin_nom_complet'] ?? ''));
        $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
        $adminPassword = (string) ($input['admin_password'] ?? '');

        if ($name === '' || mb_strlen($name) > 150) {
            return $this->errorResponse('Le nom du tenant est obligatoire (150 caractères maximum).', 422);
        }
        if ($slug === '' || strlen($slug) > 100 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->errorResponse('Le slug doit contenir uniquement des lettres minuscules, chiffres et tirets.', 422);
        }
        if ($contactEmail !== '' && ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('L’adresse de contact est invalide.', 422);
        }
        if (! $this->timezoneExists($timezone)) {
            return $this->errorResponse('Le fuseau horaire est invalide.', 422);
        }
        if ($adminName === '' || mb_strlen($adminName) > 150 || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->errorResponse('Le nom et une adresse email valide pour l’administrateur sont obligatoires.', 422);
        }
        if ($adminPassword !== '' && strlen($adminPassword) < 8) {
            return $this->errorResponse('Le mot de passe administrateur doit contenir au moins 8 caractères.', 422);
        }

        $db = Database::connect();
        if ($db->table('tenants')->where('slug', $slug)->countAllResults() > 0) {
            return $this->errorResponse('Ce slug est déjà utilisé.', 409);
        }
        if ($db->table('users')->where('email', $adminEmail)->where('deleted_at', null)->countAllResults() > 0) {
            return $this->errorResponse('Cet email est déjà associé à un compte actif.', 409);
        }

        $generatedPassword = $adminPassword === ''
            ? rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'AZ'), '=')
            : null;
        $password = $generatedPassword ?? $adminPassword;
        $db->transBegin();

        try {
            $tenantModel = new TenantModel();
            $tenantModel->skipValidation(true);
            $tenantId = $tenantModel->insert([
                'name' => $name,
                'slug' => $slug,
                'contact_email' => $contactEmail === '' ? null : $contactEmail,
                'timezone' => $timezone,
                'is_active' => 1,
            ]);
            if ($tenantId === false) {
                throw new \RuntimeException('TenantModel a refusé la création du tenant.');
            }

            $userModel = new UserModel();
            $userModel->skipValidation(true);
            $adminId = $userModel->insert([
                'tenant_id' => (int) $tenantId,
                'email' => $adminEmail,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nom_complet' => $adminName,
                'role' => 'admin',
                'is_super_admin' => 0,
                'is_active' => 1,
                'auth_source' => 'LOCAL',
            ]);
            if ($adminId === false || ! $db->transStatus()) {
                throw new \RuntimeException('UserModel a refusé la création de l’administrateur du tenant.');
            }

            $db->transCommit();
            $tenant = $this->findTenant((int) $tenantId);
            $this->audit('tenant_created', (int) $tenantId);

            return $this->successResponse([
                'tenant' => $tenant,
                'admin' => ['id' => (int) $adminId, 'email' => $adminEmail],
                'temporary_password' => $generatedPassword,
            ], 'Tenant et administrateur créés.', 201);
        } catch (Throwable $exception) {
            $db->transRollback();
            log_message('error', 'Création du tenant échouée : {error}', ['error' => $exception->getMessage()]);

            return $this->errorResponse('Impossible de créer le tenant et son administrateur.', 500);
        }
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canAdministerTenants()) {
            return $this->adminOnlyResponse();
        }

        $tenant = $this->findTenant($id);
        if ($tenant === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        $input = $this->jsonInput();
        $updates = [];
        foreach (['name', 'slug', 'contact_email', 'timezone', 'is_active'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        if ($updates === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }

        if (array_key_exists('name', $updates)) {
            $updates['name'] = trim((string) $updates['name']);
            if ($updates['name'] === '' || mb_strlen($updates['name']) > 150) {
                return $this->errorResponse('Le nom du tenant est obligatoire (150 caractères maximum).', 422);
            }
        }
        if (array_key_exists('slug', $updates)) {
            $updates['slug'] = strtolower(trim((string) $updates['slug']));
            if (strlen($updates['slug']) > 100 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $updates['slug'])) {
                return $this->errorResponse('Le slug doit contenir uniquement des lettres minuscules, chiffres et tirets.', 422);
            }
            if (Database::connect()->table('tenants')->where('slug', $updates['slug'])->where('id !=', $id)->countAllResults() > 0) {
                return $this->errorResponse('Ce slug est déjà utilisé.', 409);
            }
        }
        if (array_key_exists('contact_email', $updates)) {
            $email = strtolower(trim((string) ($updates['contact_email'] ?? '')));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse('L’adresse de contact est invalide.', 422);
            }
            $updates['contact_email'] = $email === '' ? null : $email;
        }
        if (array_key_exists('timezone', $updates)) {
            $updates['timezone'] = trim((string) $updates['timezone']);
            if (! $this->timezoneExists($updates['timezone'])) {
                return $this->errorResponse('Le fuseau horaire est invalide.', 422);
            }
        }
        if (array_key_exists('is_active', $updates)) {
            $active = filter_var($updates['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                return $this->errorResponse('Le statut actif doit être booléen.', 422);
            }
            if (! $active && (int) $this->claims()->tenant_id === $id) {
                return $this->errorResponse('Vous ne pouvez pas désactiver le tenant associé à votre session.', 400);
            }
            $updates['is_active'] = $active ? 1 : 0;
            $updates['deleted_at'] = $active ? null : date('Y-m-d H:i:s');
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');
        if (! Database::connect()->table('tenants')->where('id', $id)->update($updates)) {
            log_message('error', 'Mise à jour du tenant #{id} impossible.', ['id' => $id]);
            return $this->errorResponse('Impossible de mettre à jour ce tenant.', 500);
        }

        $this->audit('tenant_updated', $id);
        return $this->successResponse($this->findTenant($id), 'Tenant mis à jour.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canAdministerTenants()) {
            return $this->adminOnlyResponse();
        }
        if ((int) $this->claims()->tenant_id === $id) {
            return $this->errorResponse('Vous ne pouvez pas désactiver le tenant associé à votre session.', 400);
        }
        if ($this->findTenant($id) === null) {
            return $this->errorResponse('Tenant introuvable.', 404);
        }

        $now = date('Y-m-d H:i:s');
        $updated = Database::connect()->table('tenants')
            ->where('id', $id)
            ->update(['is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
        if (! $updated) {
            log_message('error', 'Désactivation du tenant #{id} impossible.', ['id' => $id]);
            return $this->errorResponse('Impossible de désactiver ce tenant.', 500);
        }

        $this->audit('tenant_deactivated', $id);
        return $this->successResponse(['id' => $id, 'is_active' => false], 'Tenant désactivé.');
    }

    private function findTenant(int $id): ?array
    {
        $row = Database::connect()->table('tenants t')
            ->select('t.id, t.name, t.slug, t.contact_email, t.timezone, t.is_active, t.created_at, t.deleted_at')
            ->select('(SELECT COUNT(*) FROM employes e WHERE e.tenant_id = t.id AND e.deleted_at IS NULL) AS employee_count', false)
            ->select('(SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count', false)
            ->where('t.id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['employee_count'] = (int) $row['employee_count'];
        $row['user_count'] = (int) $row['user_count'];

        return $row;
    }

    private function normalizeSlug(string $slug, string $name): string
    {
        $value = trim($slug);
        if ($value === '') {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            $value = $transliterated === false ? $name : $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function timezoneExists(string $timezone): bool
    {
        return $timezone === 'UTC' || in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function adminOnlyResponse(): ResponseInterface
    {
        return $this->claims() === null
            ? $this->errorResponse('Jeton invalide ou expiré.', 401)
            : $this->errorResponse('Accès réservé aux super-administrateurs.', 403);
    }

    private function canAdministerTenants(): bool
    {
        $claims = $this->claims();
        if ($claims === null || ! (bool) ($claims->is_super_admin ?? false)) {
            return false;
        }

        return (new UserModel())
            ->where('id', (int) $claims->sub)
            ->where('tenant_id', (int) $claims->tenant_id)
            ->where('is_super_admin', 1)
            ->where('is_active', 1)
            ->first() !== null;
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $parsed));
    }

    private function audit(string $action, int $tenantId): void
    {
        log_message('notice', 'Audit admin tenants: action={action} actor_id={actorId} tenant_id={tenantId}', [
            'action' => $action,
            'actorId' => (int) $this->claims()->sub,
            'tenantId' => $tenantId,
        ]);
    }
}
