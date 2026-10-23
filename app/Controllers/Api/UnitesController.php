<?php

namespace App\Controllers\Api;

use App\Services\UniteOrganisationnelleService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * API de consultation de la hiérarchie organisationnelle.
 */
class UnitesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            return $this->successResponse((new UniteOrganisationnelleService())->getArbreHierarchique($tenantId));
        } catch (Throwable $e) {
            log_message('error', 'Lecture de l’arbre organisationnel impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de charger la hiérarchie.', 500);
        }
    }

    public function descendants(int $id): ResponseInterface
    {
        if (! $this->uniteAppartientAuTenant($id)) {
            return $this->errorResponse('Unité introuvable.', 404);
        }

        try {
            return $this->successResponse((new UniteOrganisationnelleService())->getUnitesDescendantes($id));
        } catch (Throwable $e) {
            return $this->errorResponse('Impossible de charger les unités descendantes.', 500);
        }
    }

    public function employees(int $id): ResponseInterface
    {
        if (! $this->uniteAppartientAuTenant($id)) {
            return $this->errorResponse('Unité introuvable.', 404);
        }

        $recursif = filter_var($this->request->getGet('recursif') ?? true, FILTER_VALIDATE_BOOLEAN);
        try {
            return $this->successResponse((new UniteOrganisationnelleService())->getEmployesDeUnite($id, $recursif));
        } catch (Throwable $e) {
            return $this->errorResponse('Impossible de charger les employés de cette unité.', 500);
        }
    }

    private function uniteAppartientAuTenant(int $id): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null && Database::connect()->table('unites_organisationnelles')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }
}
