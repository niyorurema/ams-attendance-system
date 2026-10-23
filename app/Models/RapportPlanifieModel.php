<?php

namespace App\Models;

use CodeIgniter\Model;

/** Modèle des rapports périodiques configurés par utilisateur et tenant. */
class RapportPlanifieModel extends Model
{
    protected $table = 'rapports_planifies';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $allowedFields = [
        'tenant_id',
        'user_id',
        'nom',
        'type',
        'heure_envoi',
        'jours_semaine',
        'format',
        'langue',
        'perimetre_type',
        'perimetre_unite_id',
        'inclure_agents_bureau',
        'actif',
        'dernier_envoi',
    ];

    protected $validationRules = [
        'tenant_id' => 'required|integer|greater_than_equal_to[1]',
        'user_id' => 'required|integer|greater_than_equal_to[1]',
        'nom' => 'required|max_length[150]',
        'type' => 'required|in_list[QUOTIDIEN,HEBDOMADAIRE,MENSUEL]',
        'heure_envoi' => 'required|regex_match[/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/]',
        'jours_semaine' => 'permit_empty|max_length[20]',
        'format' => 'required|in_list[PDF,EXCEL,HTML]',
        'langue' => 'required|in_list[fr,en,rn]',
        'perimetre_type' => 'required|in_list[EQUIPE,SERVICE,DIRECTION,COMMISSARIAT,TOUS]',
        'perimetre_unite_id' => 'permit_empty|integer|greater_than_equal_to[1]',
        'inclure_agents_bureau' => 'required|in_list[0,1]',
        'actif' => 'required|in_list[0,1]',
    ];
}
