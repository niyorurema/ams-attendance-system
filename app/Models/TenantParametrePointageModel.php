<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle des paramètres configurables de pointage par tenant.
 */
class TenantParametrePointageModel extends Model
{
    protected $table = 'tenant_parametres_pointage';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'cle',
        'valeur',
        'type',
        'description',
        'modifiable_par',
    ];
}
