<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle EmployeModel.
 *
 * Représente les employés dont les données biométriques sont synchronisées
 * sur les capteurs ZKTeco. Les pointages sont reliés à cet employé via le
 * champ `badge_id` du capteur.
 */
class EmployeModel extends Model
{
    protected $table            = 'employes';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'unite_id',
        'poste',
        'est_responsable_unite',
        'matricule',
        'badge_id',
        'user_id_capteur',
        'nom',
        'prenom',
        'email',
        'telephone',
        'date_embauche',
        'date_depart',
        'genre',
        'photo_url',
        'empreinte_enrolee',
        'nb_empreintes',
        'role_capteur',
        'actif',
        'derniere_sync',
        'notes',
    ];

    protected $validationRules = [
        'tenant_id'     => 'required|integer|greater_than_equal_to[1]',
        'matricule'     => 'required|max_length[50]',
        'badge_id'      => 'required|integer|greater_than_equal_to[1]',
        'nom'           => 'required|max_length[100]',
        'prenom'        => 'required|max_length[100]',
        'email'         => 'permit_empty|valid_email|max_length[150]',
        'telephone'     => 'permit_empty|max_length[30]',
        'genre'         => 'permit_empty|in_list[M,F,AUTRE]',
        'actif'         => 'required|in_list[0,1]',
        'empreinte_enrolee' => 'required|in_list[0,1]',
    ];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id', 'id');
    }

    public function pointages()
    {
        return $this->hasMany(PointageModel::class, 'employe_id', 'id');
    }

    public function actifs()
    {
        return $this->where('actif', 1);
    }

    public function parBadge($badgeId)
    {
        return $this->where('badge_id', (int) $badgeId);
    }
}
