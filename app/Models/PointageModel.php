<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modèle PointageModel.
 *
 * Représente les événements enregistrés par les capteurs. La donnée métier
 * principale est `badge_id`, qui permet de retrouver l’employé associé.
 */
class PointageModel extends Model
{
    protected $table            = 'pointages';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useSoftDeletes    = true;
    protected $useTimestamps     = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'employe_id',
        'badge_id',
        'capteur_id',
        'type_evenement',
        'statut_calcul',
        'date_pointage',
        'date_heure_locale',
        'source',
        'statut',
        'raw_data',
        'notes',
    ];

    protected $validationRules = [
        'tenant_id'    => 'required|integer|greater_than_equal_to[1]',
        'badge_id'     => 'required|integer|greater_than_equal_to[1]',
        'date_pointage'=> 'required',
        'type_evenement' => 'required|in_list[CHECK_IN,CHECK_OUT,BREAK_IN,BREAK_OUT,UNKNOWN]',
        'statut_calcul' => 'permit_empty|in_list[VALIDE,RETARD,ANORMAL,UNKNOWN]',
        'source'       => 'required|in_list[CAPTEUR,MANUEL,IMPORT]',
        'statut'       => 'required|in_list[VALIDE,INVALIDE,REJETE,SUPPRIME]',
    ];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id', 'id');
    }

    public function employe()
    {
        return $this->belongsTo(EmployeModel::class, 'employe_id', 'id');
    }

    public function capteur()
    {
        return $this->belongsTo(CapteurModel::class, 'capteur_id', 'id');
    }

    public function parDate($date)
    {
        $date = date('Y-m-d', strtotime($date));

        return $this->where('DATE(date_pointage)', $date);
    }

    public function parEmploye($employeId)
    {
        return $this->where('employe_id', (int) $employeId);
    }

    public function parCapteur($capteurId)
    {
        return $this->where('capteur_id', (int) $capteurId);
    }

    /**
     * Évite les doublons de pointages sur la base du même tenant, badge, capteur
     * et date/heure exacte.
     */
    public function dedupliquer(): int
    {
        $sql = "DELETE p1 FROM pointages p1
                INNER JOIN pointages p2
                    ON p1.id > p2.id
                   AND p1.tenant_id = p2.tenant_id
                   AND p1.badge_id = p2.badge_id
                   AND p1.capteur_id = p2.capteur_id
                   AND p1.date_pointage = p2.date_pointage";

        return $this->db->query($sql)->getAffectedRows();
    }
}
