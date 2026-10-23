<?php

namespace App\Controllers\Api;

use App\Models\EmployeModel;
use App\Services\HoraireService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * API de consultation et d'initialisation des horaires.
 */
class HorairesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $horaires = Database::connect()->table('tenant_horaires')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('nom', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($horaires as &$horaire) {
            $horaire['plages_horaires'] = json_decode((string) $horaire['plages_horaires'], true) ?? [];
        }
        unset($horaire);

        return $this->successResponse(['items' => $horaires, 'total' => count($horaires)]);
    }

    public function employe(int $employeId): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if ((new EmployeModel())->where('tenant_id', $tenantId)->find($employeId) === null) {
            return $this->errorResponse('Employé introuvable pour ce tenant.', 404);
        }

        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 400);
        }

        try {
            $horaire = (new HoraireService())->getHoraireEmploye($employeId, $date);
            return $horaire === []
                ? $this->errorResponse('Aucun horaire applicable.', 404)
                : $this->successResponse($horaire);
        } catch (Throwable $e) {
            log_message('error', 'Lecture horaire employé #{id} impossible : {error}', ['id' => $employeId, 'error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de charger cet horaire.', 500);
        }
    }

    public function creerDefaut(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        $input = $this->jsonInput();
        $type = strtoupper(trim((string) ($input['type'] ?? 'STANDARD')));
        if (! in_array($type, ['STANDARD', 'OBR', 'FLEXIBLE'], true)) {
            return $this->errorResponse('Type d’horaire invalide.', 400);
        }

        try {
            $id = (new HoraireService())->creerHoraireDefaut((int) $tenantId, $type);
            return $this->successResponse(['id' => $id, 'type' => $type], 'Horaire par défaut disponible.', 201);
        } catch (Throwable $e) {
            log_message('error', 'Création horaire défaut impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de créer l’horaire par défaut.', 500);
        }
    }

    public function modifier(int $horaireId): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $nom = $input['nom'] ?? null;
        $description = $input['description'] ?? null;
        $plages = $input['plages_horaires'] ?? null;
        $heuresNormales = filter_var($input['heures_travail_normales'] ?? null, FILTER_VALIDATE_FLOAT);
        $seuilAnormal = filter_var($input['seuil_heures_anormal'] ?? null, FILTER_VALIDATE_FLOAT);
        if (! is_string($nom) || ($description !== null && ! is_string($description))
            || ! is_array($plages) || $heuresNormales === false || $seuilAnormal === false
        ) {
            return $this->errorResponse('Les champs de configuration de l’horaire sont invalides.', 422);
        }

        try {
            $horaire = (new HoraireService())->modifierHoraireTenant($tenantId, $horaireId, [
                'nom' => $nom,
                'description' => $description,
                'plages_horaires' => $plages,
                'heures_travail_normales' => (float) $heuresNormales,
                'seuil_heures_anormal' => (float) $seuilAnormal,
            ]);

            return $this->successResponse($horaire, 'Configuration de l’horaire enregistrée.');
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Modification horaire tenant #{tenant} impossible : {error}', [
                'tenant' => $tenantId,
                'error' => $exception->getMessage(),
            ]);

            return $this->errorResponse('Impossible d’enregistrer la configuration de l’horaire.', 500);
        }
    }

    public function definirDefaut(int $horaireId): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        try {
            (new HoraireService())->definirHoraireParDefaut($tenantId, $horaireId);

            return $this->successResponse(['id' => $horaireId], 'Horaire par défaut activé.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 404);
        } catch (Throwable $e) {
            log_message('error', 'Activation horaire défaut #{id} impossible : {error}', ['id' => $horaireId, 'error' => $e->getMessage()]);

            return $this->errorResponse('Impossible d’activer cet horaire par défaut.', 500);
        }
    }

    public function validerPlages(): ResponseInterface
    {
        $plages = $this->jsonInput()['plages_horaires'] ?? null;
        if (! is_array($plages)) {
            return $this->errorResponse('Le champ plages_horaires doit être un tableau.', 400);
        }

        $valide = (new HoraireService())->validerPlagesHoraires($plages);
        return $this->successResponse(['valide' => $valide], $valide ? 'Plages horaires valides.' : 'Plages horaires invalides.');
    }
}
