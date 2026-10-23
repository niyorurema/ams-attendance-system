<?php

namespace App\Controllers\Api;

use App\Models\EmployeModel;
use App\Models\ShiftAssignmentModel;
use App\Models\ShiftModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/** API multi-tenant de gestion des shifts et de leurs affectations. */
class ShiftsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $db = Database::connect();
        $date = date('Y-m-d');
        $shifts = $db->table('shifts s')
            ->select('s.*, COUNT(DISTINCT CASE WHEN sa.actif = 1 AND sa.deleted_at IS NULL AND sa.date_debut <= ' . $db->escape($date) . ' AND (sa.date_fin IS NULL OR sa.date_fin >= ' . $db->escape($date) . ') THEN sa.employe_id END) AS employes_assignes', false)
            ->join('shift_assignments sa', 'sa.shift_id = s.id AND sa.tenant_id = s.tenant_id', 'left')
            ->where('s.tenant_id', $tenantId)
            ->where('s.deleted_at', null)
            ->groupBy('s.id')
            ->orderBy('s.ordre_affichage', 'ASC')
            ->orderBy('s.nom', 'ASC')
            ->get()
            ->getResultArray();
        $affectations = $db->table('shift_assignments sa')
            ->select('sa.id, sa.employe_id, sa.shift_id, sa.date_debut, sa.date_fin, s.nom AS shift_nom, s.heure_debut, s.heure_fin, s.couleur, e.nom AS employe_nom, e.prenom AS employe_prenom')
            ->join('shifts s', 's.id = sa.shift_id AND s.tenant_id = sa.tenant_id')
            ->join('employes e', 'e.id = sa.employe_id AND e.tenant_id = sa.tenant_id')
            ->where('sa.tenant_id', $tenantId)
            ->where('sa.actif', 1)
            ->where('sa.deleted_at', null)
            ->where('s.deleted_at', null)
            ->where('sa.date_debut <=', $date)
            ->groupStart()
                ->where('sa.date_fin', null)
                ->orWhere('sa.date_fin >=', $date)
            ->groupEnd()
            ->orderBy('s.ordre_affichage', 'ASC')
            ->orderBy('e.nom', 'ASC')
            ->get()
            ->getResultArray();

        return $this->successResponse([
            'items' => $shifts,
            'affectations' => $affectations,
            'total' => count($shifts),
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $shift = $this->trouverShift($id);

        return $shift === null
            ? $this->errorResponse('Shift introuvable.', 404)
            : $this->successResponse($shift);
    }

    public function create(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $data = $this->validerShift($this->jsonInput());
        if ($data instanceof ResponseInterface) {
            return $data;
        }
        $data['tenant_id'] = $tenantId;
        $model = new ShiftModel();
        if (! $model->insert($data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($this->trouverShift((int) $model->getInsertID()), 'Shift créé.', 201);
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverShift($id) === null) {
            return $this->errorResponse('Shift introuvable.', 404);
        }

        $data = $this->validerShift($this->jsonInput());
        if ($data instanceof ResponseInterface) {
            return $data;
        }
        $model = new ShiftModel();
        if (! $model->update($id, $data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($this->trouverShift($id), 'Shift modifié.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        if ($this->trouverShift($id) === null) {
            return $this->errorResponse('Shift introuvable.', 404);
        }

        $db = Database::connect();
        $db->transStart();
        $db->table('shift_assignments')
            ->where('shift_id', $id)
            ->where('tenant_id', $this->tenantId())
            ->where('deleted_at', null)
            ->update([
                'actif' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'deleted_at' => date('Y-m-d H:i:s'),
            ]);
        (new ShiftModel())->delete($id);
        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->errorResponse('Impossible de supprimer ce shift.', 500);
        }

        return $this->successResponse(null, 'Shift supprimé.');
    }

    public function assigner(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $employeId = filter_var($input['employe_id'] ?? null, FILTER_VALIDATE_INT);
        $shiftId = filter_var($input['shift_id'] ?? null, FILTER_VALIDATE_INT);
        $dateDebut = (string) ($input['date_debut'] ?? '');
        $dateFin = isset($input['date_fin']) && $input['date_fin'] !== '' ? (string) $input['date_fin'] : null;
        if ($employeId === false || $employeId < 1 || $shiftId === false || $shiftId < 1
            || ! $this->dateValide($dateDebut)
            || ($dateFin !== null && (! $this->dateValide($dateFin) || $dateFin < $dateDebut))
        ) {
            return $this->errorResponse('Les données d’affectation ou les dates sont invalides.', 400);
        }
        if ((new EmployeModel())->where('tenant_id', $tenantId)->where('actif', 1)->find($employeId) === null) {
            return $this->errorResponse('Employé introuvable ou inactif pour ce tenant.', 404);
        }
        if ($this->trouverShift((int) $shiftId) === null) {
            return $this->errorResponse('Shift introuvable pour ce tenant.', 404);
        }

        $db = Database::connect();
        $db->transBegin();
        try {
            $db->query('SELECT id FROM employes WHERE id = ? AND tenant_id = ? FOR UPDATE', [(int) $employeId, $tenantId]);
            $conflit = $db->table('shift_assignments')
                ->where('tenant_id', $tenantId)
                ->where('employe_id', (int) $employeId)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->where('date_debut <=', $dateFin ?? '9999-12-31')
                ->groupStart()
                    ->where('date_fin', null)
                    ->orWhere('date_fin >=', $dateDebut)
                ->groupEnd()
                ->countAllResults();
            if ($conflit > 0) {
                $db->transRollback();
                return $this->errorResponse('Cet employé a déjà un shift actif sur cette période.', 409);
            }

            $model = new ShiftAssignmentModel();
            if (! $model->insert([
                'tenant_id' => $tenantId,
                'employe_id' => (int) $employeId,
                'shift_id' => (int) $shiftId,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'actif' => 1,
            ])) {
                throw new \RuntimeException(implode(' ', $model->errors()));
            }
            $assignmentId = (int) $model->getInsertID();
            $db->transCommit();

            return $this->successResponse($this->trouverAffectation($assignmentId), 'Employé affecté au shift.', 201);
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Affectation shift impossible : {error}', ['error' => $e->getMessage()]);

            return $this->errorResponse('Impossible d’affecter cet employé au shift.', 500);
        }
    }

    public function desassigner(int $employeId): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if ((new EmployeModel())->where('tenant_id', $tenantId)->find($employeId) === null) {
            return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
        }

        $affectation = Database::connect()->table('shift_assignments')
            ->where('tenant_id', $tenantId)
            ->where('employe_id', $employeId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->where('date_debut <=', date('Y-m-d'))
            ->groupStart()
                ->where('date_fin', null)
                ->orWhere('date_fin >=', date('Y-m-d'))
            ->groupEnd()
            ->orderBy('date_debut', 'DESC')
            ->get()
            ->getRowArray();
        if ($affectation === null) {
            return $this->errorResponse('Aucune affectation active à retirer.', 404);
        }

        (new ShiftAssignmentModel())->delete((int) $affectation['id']);

        return $this->successResponse(null, 'Affectation retirée.');
    }

    public function getEmployeShifts(int $employeId): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if ((new EmployeModel())->where('tenant_id', $tenantId)->find($employeId) === null) {
            return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
        }

        $items = Database::connect()->table('shift_assignments sa')
            ->select('sa.*, s.nom AS shift_nom, s.code AS shift_code, s.heure_debut, s.heure_fin, s.couleur')
            ->join('shifts s', 's.id = sa.shift_id AND s.tenant_id = sa.tenant_id')
            ->where('sa.tenant_id', $tenantId)
            ->where('sa.employe_id', $employeId)
            ->where('sa.deleted_at', null)
            ->orderBy('sa.date_debut', 'DESC')
            ->get()
            ->getResultArray();

        return $this->successResponse(['items' => $items, 'total' => count($items)]);
    }

    /** @return ?array<string, mixed> */
    private function trouverShift(int $id): ?array
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return null;
        }
        $shift = (new ShiftModel())->where('tenant_id', $tenantId)->find($id);

        return is_array($shift) ? $shift : null;
    }

    /** @return ?array<string, mixed> */
    private function trouverAffectation(int $id): ?array
    {
        $tenantId = $this->tenantId();
        $row = Database::connect()->table('shift_assignments sa')
            ->select('sa.*, s.nom AS shift_nom, s.code AS shift_code, s.heure_debut, s.heure_fin, e.nom AS employe_nom, e.prenom AS employe_prenom')
            ->join('shifts s', 's.id = sa.shift_id AND s.tenant_id = sa.tenant_id')
            ->join('employes e', 'e.id = sa.employe_id AND e.tenant_id = sa.tenant_id')
            ->where('sa.id', $id)
            ->where('sa.tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $row;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|ResponseInterface
     */
    private function validerShift(array $input): array|ResponseInterface
    {
        $data = array_intersect_key($input, array_flip([
            'nom', 'code', 'description', 'heure_debut', 'heure_fin', 'couleur', 'ordre_affichage', 'actif',
        ]));
        $heureDebut = (string) ($data['heure_debut'] ?? '');
        $heureFin = (string) ($data['heure_fin'] ?? '');
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $heureDebut) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $heureFin) !== 1
            || substr($heureDebut, 0, 5) === substr($heureFin, 0, 5)
        ) {
            return $this->errorResponse('Les heures doivent être valides et différentes.', 400);
        }
        if (isset($data['code']) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', (string) $data['code']) !== 1) {
            return $this->errorResponse('Le code doit contenir uniquement des lettres, chiffres, tirets ou underscores.', 400);
        }
        if (isset($data['couleur']) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $data['couleur']) !== 1) {
            return $this->errorResponse('La couleur doit être au format hexadécimal #RRGGBB.', 400);
        }
        $data['heure_debut'] = strlen($heureDebut) === 5 ? $heureDebut . ':00' : $heureDebut;
        $data['heure_fin'] = strlen($heureFin) === 5 ? $heureFin . ':00' : $heureFin;
        $data['couleur'] ??= '#3b82f6';
        $data['ordre_affichage'] ??= 0;
        $data['actif'] ??= 1;
        if (is_bool($data['actif'])) {
            $data['actif'] = (int) $data['actif'];
        }
        if (! in_array($data['actif'], [0, 1, '0', '1'], true)) {
            return $this->errorResponse('Le statut actif doit être 0 ou 1.', 400);
        }
        $data['actif'] = (int) $data['actif'];

        return $data;
    }

    private function dateValide(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
