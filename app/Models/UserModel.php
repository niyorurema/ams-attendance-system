<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle UserModel.
 *
 * Représente les comptes applicatifs internes. Les utilisateurs sont rattachés
 * à un tenant et peuvent avoir un droit global via `is_super_admin`.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $hidden = [
        'password_hash',
    ];

    protected $allowedFields = [
        'tenant_id',
        'employe_id',
        'email',
        'password_hash',
        'nom_complet',
        'telephone',
        'role',
        'is_super_admin',
        'is_active',
        'auth_source',
        'webauthn_enrollment_approved',
        'derniere_connexion',
    ];

    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'email'     => 'permit_empty|valid_email|max_length[150]',
        'password_hash' => 'permit_empty|max_length[255]',
        'role'      => 'required|in_list[admin,rh,superviseur,chef_service,directeur,commissaire,lecteur]',
        'is_active' => 'required|in_list[0,1]',
        'is_super_admin' => 'required|in_list[0,1]',
    ];

    protected $validationMessages = [
        'email' => [
            'required' => 'L’adresse e-mail est obligatoire.',
            'valid_email' => 'L’adresse e-mail n’est pas valide.',
        ],
        'role' => [
            'in_list' => 'Le rôle sélectionné est invalide.',
        ],
    ];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id', 'id');
    }
    public function sessions()
    {
        return $this->hasMany('App\Models\SessionModel', 'user_id', 'id');
    }
}
