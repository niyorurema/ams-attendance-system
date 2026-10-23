<?php

namespace App\Controllers\Api;

use App\Models\EmployeModel;
use App\Services\ContratsHoraireService;
use CodeIgniter\HTTP\ResponseInterface;

/** API des contrats horaires; distincte des affectations d'horaires. */
class ContratsHoraireController extends BaseApiController
{
    public function index(int $employeId): ResponseInterface
    {
        return $this->employe($employeId) === null ? $this->errorResponse('Employé introuvable.', 404) : $this->successResponse((new ContratsHoraireService())->getHistorique($employeId));
    }
    public function actif(int $employeId): ResponseInterface
    {
        if ($this->employe($employeId) === null) return $this->errorResponse('Employé introuvable.', 404);
        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        try { return $this->successResponse((new ContratsHoraireService())->getContratActif($employeId, $date)); } catch (\InvalidArgumentException $e) { return $this->errorResponse($e->getMessage(), 422); }
    }
    public function create(int $employeId): ResponseInterface
    {
        if (! $this->canManage()) return $this->errorResponse('Droits de gestion requis.', 403);
        $employe = $this->employe($employeId); if ($employe === null) return $this->errorResponse('Employé introuvable.', 404);
        try { return $this->successResponse((new ContratsHoraireService())->creer((int) $employe['tenant_id'], $employeId, $this->jsonInput()), 'Contrat horaire créé.', 201); } catch (\DomainException $e) { return $this->errorResponse($e->getMessage(), 409); } catch (\InvalidArgumentException $e) { return $this->errorResponse($e->getMessage(), 422); }
    }
    public function update(int $employeId, int $contratId): ResponseInterface
    {
        if (! $this->canManage()) return $this->errorResponse('Droits de gestion requis.', 403);
        $employe = $this->employe($employeId); $service = new ContratsHoraireService(); $contrat = $service->trouver($contratId);
        if ($employe === null || $contrat === null || (int) $contrat['employe_id'] !== $employeId || (int) $contrat['tenant_id'] !== (int) $employe['tenant_id']) return $this->errorResponse('Contrat introuvable.', 404);
        try { return $this->successResponse($service->modifier($contratId, $this->jsonInput()), 'Contrat horaire modifié.'); } catch (\DomainException $e) { return $this->errorResponse($e->getMessage(), 409); } catch (\InvalidArgumentException $e) { return $this->errorResponse($e->getMessage(), 422); }
    }
    public function delete(int $employeId, int $contratId): ResponseInterface
    {
        if (! $this->canManage()) return $this->errorResponse('Droits de gestion requis.', 403);
        $employe = $this->employe($employeId); $service = new ContratsHoraireService(); $contrat = $service->trouver($contratId);
        if ($employe === null || $contrat === null || (int) $contrat['employe_id'] !== $employeId || (int) $contrat['tenant_id'] !== (int) $employe['tenant_id']) return $this->errorResponse('Contrat introuvable.', 404);
        return $service->supprimer($contratId) ? $this->successResponse(null, 'Contrat horaire supprimé.') : $this->errorResponse('Impossible de supprimer le contrat.', 500);
    }
    /** @return array<string,mixed>|null */
    private function employe(int $employeId): ?array { $tenantId = $this->tenantId(); return $tenantId === null ? null : (new EmployeModel())->where('tenant_id', $tenantId)->find($employeId); }
}
