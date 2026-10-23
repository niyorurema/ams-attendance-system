<?php

namespace App\Models;

use CodeIgniter\Model;

class ShiftAssignmentModel extends Model
{
    protected $table = 'shift_assignments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    protected $allowedFields = [
        'tenant_id', 'employe_id', 'shift_id', 'date_debut', 'date_fin', 'actif',
    ];

    /** @return ?array<string, mixed> */
    public function getShiftPourDate(int $tenantId, int $employeId, string $date): ?array
    {
        $resultat = $this->select('shift_assignments.*, shifts.nom, shifts.code, shifts.heure_debut, shifts.heure_fin, shifts.couleur')
            ->join('shifts', 'shifts.id = shift_assignments.shift_id AND shifts.tenant_id = shift_assignments.tenant_id')
            ->where('shift_assignments.tenant_id', $tenantId)
            ->where('shift_assignments.employe_id', $employeId)
            ->where('shift_assignments.actif', 1)
            ->where('shifts.actif', 1)
            ->where('shift_assignments.date_debut <=', $date)
            ->groupStart()
                ->where('shift_assignments.date_fin', null)
                ->orWhere('shift_assignments.date_fin >=', $date)
            ->groupEnd()
            ->orderBy('shift_assignments.date_debut', 'DESC')
            ->first();

        return is_array($resultat) ? $resultat : null;
    }
}
