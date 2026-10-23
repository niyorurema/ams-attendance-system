<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Gestion tenant-scoped des services organisationnels et de leurs mandats.
 */
final class OrganisationController extends BaseApiController
{
    private const TYPES_UNITE = ['COMMISSARIAT', 'DIRECTION', 'SERVICE', 'EQUIPE'];
    private const TYPES_MANDAT = ['TITULAIRE', 'INTERIM', 'ADJOINT', 'INTERIM_ADJOINT'];

    public function index(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            $db = Database::connect();
            $unites = $db->table('unites_organisationnelles u')
                ->select('u.id, u.parent_id, u.nom, u.code, u.type, u.niveau, u.adresse, u.telephone, u.email, u.responsable_id, u.ordre_affichage, u.actif, u.source_id, u.source_systeme, u.source_synced_at, u.ams_override, p.nom AS parent_nom')
                ->join('unites_organisationnelles p', 'p.id = u.parent_id AND p.tenant_id = u.tenant_id', 'left')
                ->where('u.tenant_id', $tenantId)
                ->where('u.deleted_at', null)
                ->orderBy('u.niveau', 'ASC')
                ->orderBy('u.ordre_affichage', 'ASC')
                ->orderBy('u.nom', 'ASC')
                ->get()
                ->getResultArray();

            $mandats = $db->table('unit_chefs m')
                ->select('m.id, m.unite_id, m.user_id, m.type_mandat, m.date_debut, m.date_fin, m.est_actif, m.remarque, m.source_id, m.source_systeme, m.source_synced_at, m.ams_override, u.nom AS unite_nom, a.nom_complet AS titulaire_nom, e.nom AS employe_nom, e.prenom AS employe_prenom')
                ->join('unites_organisationnelles u', 'u.id = m.unite_id AND u.tenant_id = m.tenant_id', 'left')
                ->join('users a', 'a.id = m.user_id AND a.tenant_id = m.tenant_id', 'left')
                ->join('employes e', 'e.id = a.employe_id AND e.tenant_id = m.tenant_id', 'left')
                ->where('m.tenant_id', $tenantId)
                ->where('m.deleted_at', null)
                ->orderBy('m.est_actif', 'DESC')
                ->orderBy('m.date_debut', 'DESC')
                ->get()
                ->getResultArray();

            $users = $db->table('users a')
                ->select('a.id, a.nom_complet, a.email, a.role, a.employe_id, e.matricule')
                ->join('employes e', 'e.id = a.employe_id AND e.tenant_id = a.tenant_id', 'left')
                ->where('a.tenant_id', $tenantId)
                ->where('a.is_active', 1)
                ->where('a.deleted_at', null)
                ->orderBy('a.nom_complet', 'ASC')
                ->get()
                ->getResultArray();

            return $this->successResponse([
                'unites' => $unites,
                'mandats' => $mandats,
                'utilisateurs' => $users,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Chargement de la gestion organisationnelle impossible : {error}', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Impossible de charger les services et mandats.', 500);
        }
    }

    public function createUnite(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $validation = $this->validerUnite($input, $tenantId);
        if ($validation !== null) {
            return $validation;
        }

        $db = Database::connect();
        $parentId = $this->nullableId($input['parent_id'] ?? null);
        $parent = $parentId === null ? null : $this->trouverUnite($db, $tenantId, $parentId);
        $data = $this->donneesUnite($input);
        $data['tenant_id'] = $tenantId;
        $data['parent_id'] = $parentId;
        $data['niveau'] = $parent === null ? 1 : (int) $parent['niveau'] + 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            if (! $db->table('unites_organisationnelles')->insert($data)) {
                return $this->errorResponse('Impossible de créer le service.', 500);
            }

            return $this->successResponse(['id' => (int) $db->insertID()], 'Service créé.', 201);
        } catch (Throwable $e) {
            return $this->erreurEcriture($e, 'Impossible de créer le service.');
        }
    }

    public function updateUnite(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $db = Database::connect();
        $unite = $this->trouverUnite($db, $tenantId, $id);
        if ($unite === null) {
            return $this->errorResponse('Service introuvable.', 404);
        }
        $validation = $this->validerUnite($input, $tenantId, $id);
        if ($validation !== null) {
            return $validation;
        }

        $parentId = array_key_exists('parent_id', $input)
            ? $this->nullableId($input['parent_id'])
            : ($unite['parent_id'] === null ? null : (int) $unite['parent_id']);
        if ($parentId === $id) {
            return $this->errorResponse('Un service ne peut pas être son propre parent.', 422);
        }
        $parent = $parentId === null ? null : $this->trouverUnite($db, $tenantId, $parentId);
        if ($parentId !== null && $parent === null) {
            return $this->errorResponse('Le service parent est introuvable dans ce tenant.', 422);
        }
        if ($parentId !== null && $this->parentEstDescendant($db, $tenantId, $parentId, $id)) {
            return $this->errorResponse('Cette modification créerait un cycle dans la hiérarchie.', 422);
        }

        $data = $this->donneesUnite($input, true);
        if (array_key_exists('parent_id', $input)) {
            $data['parent_id'] = $parentId;
            $data['niveau'] = $parent === null ? 1 : (int) $parent['niveau'] + 1;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        if (($unite['source_systeme'] ?? null) !== null) {
            $data['ams_override'] = 1;
        }

        try {
            if (! $db->table('unites_organisationnelles')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->update($data)
            ) {
                return $this->errorResponse('Impossible de mettre à jour le service.', 500);
            }
            if (array_key_exists('parent_id', $input)) {
                $this->actualiserNiveauxDescendants($db, $tenantId, $id, (int) $data['niveau']);
            }

            return $this->successResponse(['id' => $id], 'Service mis à jour.');
        } catch (Throwable $e) {
            return $this->erreurEcriture($e, 'Impossible de mettre à jour le service.');
        }
    }

    public function deleteUnite(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $db = Database::connect();
        $unite = $this->trouverUnite($db, $tenantId, $id);
        if ($unite === null) {
            return $this->errorResponse('Service introuvable.', 404);
        }
        $hasChildren = $db->table('unites_organisationnelles')
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $id)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
        $hasEmployees = $db->table('employes')
            ->where('tenant_id', $tenantId)
            ->where('unite_id', $id)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
        $hasMandates = $db->table('unit_chefs')
            ->where('tenant_id', $tenantId)
            ->where('unite_id', $id)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
        if ($hasChildren || $hasEmployees || $hasMandates) {
            return $this->errorResponse('Ce service contient des sous-services, employés ou mandats. Désactivez-le plutôt que de le supprimer.', 409);
        }

        $now = date('Y-m-d H:i:s');
        $deleteData = ['actif' => 0, 'deleted_at' => $now, 'updated_at' => $now];
        if (($unite['source_systeme'] ?? null) !== null) {
            $deleteData['ams_override'] = 1;
        }
        $db->table('unites_organisationnelles')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update($deleteData);

        return $this->successResponse(['id' => $id], 'Service supprimé.');
    }

    public function createMandat(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $validation = $this->validerMandat($input, $tenantId);
        if ($validation !== null) {
            return $validation;
        }
        $data = $this->donneesMandat($input);
        $data['tenant_id'] = $tenantId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $db = Database::connect();
            if (! $db->table('unit_chefs')->insert($data)) {
                return $this->errorResponse('Impossible de créer le mandat.', 500);
            }

            return $this->successResponse(['id' => (int) $db->insertID()], 'Mandat créé.', 201);
        } catch (Throwable $e) {
            return $this->erreurEcriture($e, 'Impossible de créer le mandat.');
        }
    }

    public function updateMandat(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        $db = Database::connect();
        $mandat = $db->table('unit_chefs')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($mandat === null) {
            return $this->errorResponse('Mandat introuvable.', 404);
        }

        $input = $this->jsonInput();
        $validation = $this->validerMandat($input, $tenantId, $mandat);
        if ($validation !== null) {
            return $validation;
        }
        $data = $this->donneesMandat($input, true, $mandat);
        $data['updated_at'] = date('Y-m-d H:i:s');
        if (($mandat['source_systeme'] ?? null) !== null) {
            $data['ams_override'] = 1;
        }

        try {
            if (! $db->table('unit_chefs')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->update($data)
            ) {
                return $this->errorResponse('Impossible de mettre à jour le mandat.', 500);
            }

            return $this->successResponse(['id' => $id], 'Mandat mis à jour.');
        } catch (Throwable $e) {
            return $this->erreurEcriture($e, 'Impossible de mettre à jour le mandat.');
        }
    }

    public function deleteMandat(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $db = Database::connect();
        $mandat = $db->table('unit_chefs')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        if ($mandat === null) {
            return $this->errorResponse('Mandat introuvable ou déjà supprimé.', 404);
        }
        if ((int) $mandat['est_actif'] === 1) {
            return $this->errorResponse('Ce mandat est actif. Désactivez-le avant de le supprimer.', 409);
        }
        $deleteData = ['est_actif' => 0, 'deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        if (($mandat['source_systeme'] ?? null) !== null) {
            $deleteData['ams_override'] = 1;
        }
        $result = $db->table('unit_chefs')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->update($deleteData);
        if (! $result || $db->affectedRows() !== 1) {
            return $this->errorResponse('Mandat introuvable ou déjà supprimé.', 404);
        }

        return $this->successResponse(['id' => $id], 'Mandat supprimé.');
    }

    private function validerUnite(array $input, int $tenantId, ?int $currentId = null): ?ResponseInterface
    {
        if ($currentId === null) {
            foreach (['nom', 'code', 'type'] as $requiredField) {
                if (! array_key_exists($requiredField, $input)) {
                    return $this->errorResponse('Le nom, le code et le type du service sont requis.', 422);
                }
            }
        } elseif (array_intersect(array_keys($input), [
            'nom', 'code', 'type', 'parent_id', 'adresse', 'telephone', 'email',
            'responsable_id', 'ordre_affichage', 'actif',
        ]) === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }

        if (array_key_exists('nom', $input) && (! is_string($input['nom']) || trim($input['nom']) === '' || mb_strlen(trim($input['nom'])) > 150)) {
            return $this->errorResponse('Le nom du service est requis (150 caractères maximum).', 422);
        }
        if (array_key_exists('code', $input) && (! is_string($input['code']) || trim($input['code']) === '' || mb_strlen(trim($input['code'])) > 50)) {
            return $this->errorResponse('Le code du service est requis (50 caractères maximum).', 422);
        }
        if (array_key_exists('type', $input) && ! in_array($input['type'], self::TYPES_UNITE, true)) {
            return $this->errorResponse('Le type de service est invalide.', 422);
        }
        if (array_key_exists('parent_id', $input) && $this->nullableId($input['parent_id']) === null && $input['parent_id'] !== null && $input['parent_id'] !== '') {
            return $this->errorResponse('Le service parent est invalide.', 422);
        }
        $db = Database::connect();
        $parentId = array_key_exists('parent_id', $input) ? $this->nullableId($input['parent_id']) : null;
        if ($parentId !== null && $this->trouverUnite($db, $tenantId, $parentId) === null) {
            return $this->errorResponse('Le service parent est introuvable dans ce tenant.', 422);
        }
        if (array_key_exists('responsable_id', $input)) {
            $responsableId = $this->nullableId($input['responsable_id']);
            if ($input['responsable_id'] !== null && $input['responsable_id'] !== '' && $responsableId === null) {
                return $this->errorResponse('Le responsable sélectionné est invalide.', 422);
            }
            if ($responsableId !== null
                && $db->table('employes')->where('id', $responsableId)->where('tenant_id', $tenantId)->where('deleted_at', null)->countAllResults() === 0
            ) {
                return $this->errorResponse('Le responsable doit être un employé de ce tenant.', 422);
            }
        }
        if (array_key_exists('email', $input) && $input['email'] !== null && $input['email'] !== ''
            && (! is_string($input['email']) || filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false)
        ) {
            return $this->errorResponse('L’adresse email du service est invalide.', 422);
        }
        foreach (['adresse', 'telephone'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && ! is_string($input[$field])) {
                return $this->errorResponse('Les coordonnées du service doivent être du texte.', 422);
            }
        }
        if (array_key_exists('ordre_affichage', $input)
            && (! is_numeric($input['ordre_affichage']) || (int) $input['ordre_affichage'] < 0)
        ) {
            return $this->errorResponse('L’ordre d’affichage doit être un entier positif ou nul.', 422);
        }
        if (array_key_exists('actif', $input)
            && ! in_array($input['actif'], [true, false, 0, 1, '0', '1'], true)
        ) {
            return $this->errorResponse('Le statut du service est invalide.', 422);
        }
        if (array_key_exists('code', $input)) {
            $codeQuery = $db->table('unites_organisationnelles')
                ->where('tenant_id', $tenantId)
                ->where('code', trim((string) $input['code']))
                ->where('deleted_at', null);
            if ($currentId !== null) {
                $codeQuery->where('id !=', $currentId);
            }
            if ($codeQuery->countAllResults() > 0) {
                return $this->errorResponse('Ce code de service est déjà utilisé dans ce tenant.', 409);
            }
        }

        return null;
    }

    private function validerMandat(array $input, int $tenantId, ?array $current = null): ?ResponseInterface
    {
        if ($current === null) {
            foreach (['unite_id', 'user_id', 'type_mandat', 'date_debut'] as $requiredField) {
                if (! array_key_exists($requiredField, $input)) {
                    return $this->errorResponse('Le service, le titulaire, le type et la date de début du mandat sont requis.', 422);
                }
            }
        } elseif (array_intersect(array_keys($input), [
            'unite_id', 'user_id', 'type_mandat', 'date_debut', 'date_fin', 'est_actif', 'remarque',
        ]) === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }

        $uniteId = array_key_exists('unite_id', $input) ? $this->nullableId($input['unite_id']) : (int) ($current['unite_id'] ?? 0);
        $userId = array_key_exists('user_id', $input) ? $this->nullableId($input['user_id']) : (int) ($current['user_id'] ?? 0);
        if ($uniteId === null || $this->trouverUnite(Database::connect(), $tenantId, $uniteId) === null) {
            return $this->errorResponse('Le service sélectionné est introuvable dans ce tenant.', 422);
        }
        if ($userId === null || Database::connect()->table('users')->where('id', $userId)->where('tenant_id', $tenantId)->where('is_active', 1)->where('deleted_at', null)->countAllResults() === 0) {
            return $this->errorResponse('Le titulaire doit être un utilisateur actif de ce tenant.', 422);
        }
        if (array_key_exists('type_mandat', $input) && ! in_array($input['type_mandat'], self::TYPES_MANDAT, true)) {
            return $this->errorResponse('Le type de mandat est invalide.', 422);
        }

        $dateDebut = array_key_exists('date_debut', $input) ? $input['date_debut'] : ($current['date_debut'] ?? null);
        $dateFin = array_key_exists('date_fin', $input) ? $input['date_fin'] : ($current['date_fin'] ?? null);
        if (! $this->dateValide($dateDebut) || ($dateFin !== null && $dateFin !== '' && ! $this->dateValide($dateFin))) {
            return $this->errorResponse('Les dates du mandat doivent être au format AAAA-MM-JJ.', 422);
        }
        if ($dateFin !== null && $dateFin !== '' && $dateFin < $dateDebut) {
            return $this->errorResponse('La date de fin ne peut pas précéder la date de début.', 422);
        }
        if (array_key_exists('est_actif', $input)
            && ! in_array($input['est_actif'], [true, false, 0, 1, '0', '1'], true)
        ) {
            return $this->errorResponse('Le statut du mandat est invalide.', 422);
        }
        if (array_key_exists('remarque', $input)
            && $input['remarque'] !== null
            && (! is_string($input['remarque']) || mb_strlen($input['remarque']) > 1000)
        ) {
            return $this->errorResponse('La remarque doit être un texte de 1 000 caractères maximum.', 422);
        }

        return null;
    }

    private function donneesUnite(array $input, bool $partial = false): array
    {
        $fields = ['nom', 'code', 'type', 'adresse', 'telephone', 'email', 'responsable_id', 'ordre_affichage', 'actif'];
        $data = array_intersect_key($input, array_flip($fields));
        foreach (['nom', 'code', 'type', 'adresse', 'telephone', 'email'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '' && in_array($field, ['adresse', 'telephone', 'email'], true)) {
                    $data[$field] = null;
                }
            }
        }
        if (array_key_exists('responsable_id', $data)) {
            $data['responsable_id'] = $this->nullableId($data['responsable_id']);
        }
        if (array_key_exists('actif', $data)) {
            $data['actif'] = filter_var($data['actif'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        } elseif (! $partial) {
            $data['actif'] = 1;
        }
        if (array_key_exists('ordre_affichage', $data)) {
            $data['ordre_affichage'] = max(0, (int) $data['ordre_affichage']);
        }

        return $data;
    }

    private function donneesMandat(array $input, bool $partial = false, ?array $current = null): array
    {
        $fields = ['unite_id', 'user_id', 'type_mandat', 'date_debut', 'date_fin', 'est_actif', 'remarque'];
        $data = array_intersect_key($input, array_flip($fields));
        foreach (['unite_id', 'user_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (int) $data[$field];
            }
        }
        if (array_key_exists('date_fin', $data) && ($data['date_fin'] === '' || $data['date_fin'] === null)) {
            $data['date_fin'] = null;
        }
        if (array_key_exists('remarque', $data) && is_string($data['remarque'])) {
            $data['remarque'] = trim($data['remarque']) === '' ? null : trim($data['remarque']);
        }
        if (array_key_exists('est_actif', $data)) {
            $data['est_actif'] = filter_var($data['est_actif'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        } elseif (! $partial) {
            $data['est_actif'] = 1;
        }

        return $data;
    }

    private function trouverUnite($db, int $tenantId, int $id): ?array
    {
        $unite = $db->table('unites_organisationnelles')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $unite ?: null;
    }

    private function parentEstDescendant($db, int $tenantId, int $possibleDescendantId, int $uniteId): bool
    {
        $currentId = $possibleDescendantId;
        $visited = [];
        while ($currentId > 0 && ! isset($visited[$currentId])) {
            if ($currentId === $uniteId) {
                return true;
            }
            $visited[$currentId] = true;
            $row = $db->table('unites_organisationnelles')
                ->select('parent_id')
                ->where('id', $currentId)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            $currentId = (int) ($row['parent_id'] ?? 0);
        }

        return false;
    }

    private function actualiserNiveauxDescendants($db, int $tenantId, int $parentId, int $niveau): void
    {
        $children = $db->table('unites_organisationnelles')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $parentId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $childLevel = $niveau + 1;
            $db->table('unites_organisationnelles')
                ->where('id', $childId)
                ->where('tenant_id', $tenantId)
                ->update(['niveau' => $childLevel, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->actualiserNiveauxDescendants($db, $tenantId, $childId, $childLevel);
        }
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    private function dateValide(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function erreurEcriture(Throwable $exception, string $message): ResponseInterface
    {
        log_message('error', '{message} Détail : {detail}', [
            'message' => $message,
            'detail' => $exception->getMessage(),
        ]);
        if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
            return $this->errorResponse('Un enregistrement identique existe déjà.', 409);
        }

        return $this->errorResponse($message, 500);
    }
}
