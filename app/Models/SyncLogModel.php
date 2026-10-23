<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle SyncLogModel.
 *
 * Journalise les opérations de synchronisation avec les capteurs. Il permet de
 * tracer les réussites, erreurs, timeouts et volumes de données importés.
 */
class SyncLogModel extends Model
{
    protected $table            = 'sync_logs';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'capteur_id',
        'type_operation',
        'statut',
        'message',
        'details',
        'nombre_utilisateurs',
        'nombre_pointages',
        'duree_ms',
    ];

    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'type_operation' => 'required|in_list[SYNC_USERS,SYNC_ATTENDANCE,SCAN_DEVICE,PING,OTHER]',
        'statut' => 'required|in_list[SUCCESS,ERROR,PARTIAL,TIMEOUT]',
    ];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id', 'id');
    }

    public function capteur()
    {
        return $this->belongsTo(CapteurModel::class, 'capteur_id', 'id');
    }
}
