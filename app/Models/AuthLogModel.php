<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle des journaux d'authentification.
 */
class AuthLogModel extends Model
{
    protected $table = 'auth_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps = false;

    protected $allowedFields = [
        'tenant_id',
        'user_id',
        'email',
        'auth_source',
        'statut',
        'ip_address',
        'user_agent',
        'message_erreur',
        'created_at',
    ];
}
