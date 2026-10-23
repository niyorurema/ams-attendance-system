<?php

namespace App\Models;

use CodeIgniter\Model;

class ShiftModel extends Model
{
    protected $table = 'shifts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
    protected $allowedFields = [
        'tenant_id', 'nom', 'code', 'description', 'heure_debut', 'heure_fin',
        'couleur', 'ordre_affichage', 'actif',
    ];
    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'nom' => 'required|max_length[100]',
        'code' => 'required|max_length[20]|alpha_dash',
        'description' => 'permit_empty|max_length[255]',
        'heure_debut' => 'required',
        'heure_fin' => 'required',
        'couleur' => 'required|regex_match[/^#[0-9A-Fa-f]{6}$/]',
        'ordre_affichage' => 'required|integer',
        'actif' => 'required|in_list[0,1]',
    ];

    /** @return array<int, array<string, mixed>> */
    public function getActifs(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->orderBy('ordre_affichage', 'ASC')
            ->orderBy('nom', 'ASC')
            ->findAll();
    }
}
