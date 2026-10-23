<?php

namespace App\Controllers\Api;

use App\Models\JourFerieModel;
use App\Services\JourFerieService;
use CodeIgniter\HTTP\ResponseInterface;

class JoursFeriesController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $annee = $this->request->getGet('annee');
        $mois = $this->request->getGet('mois');
        $type = $this->request->getGet('type');
        if (($annee !== null && $annee !== '' && (! ctype_digit((string) $annee) || (int) $annee < 2000 || (int) $annee > 2100))
            || ($mois !== null && $mois !== '' && (! ctype_digit((string) $mois) || (int) $mois < 1 || (int) $mois > 12))
        ) {
            return $this->errorResponse('Les filtres année ou mois sont invalides.', 400);
        }
        if ($type !== null && $type !== '' && $type !== 'all'
            && ! in_array($type, ['NATIONAL', 'RELIGIEUX', 'PERSONNALISE', 'CONGE'], true)
        ) {
            return $this->errorResponse('Type de jour férié invalide.', 400);
        }

        $model = new JourFerieModel();
        if ($annee !== null && $annee !== '') {
            $moisValide = $mois !== null && $mois !== '' ? (int) $mois : 1;
            $debut = sprintf('%04d-%02d-01', (int) $annee, $moisValide);
            $fin = $mois !== null && $mois !== ''
                ? date('Y-m-t', strtotime($debut))
                : sprintf('%04d-12-31', (int) $annee);
            $items = $model->getPourPeriode($tenantId, $debut, $fin, false);
        } else {
            $items = $model->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->orderBy('date', 'ASC')
                ->findAll();
        }

        if ($type !== null && $type !== '' && $type !== 'all') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['type'] === $type
            ));
        }

        return $this->successResponse([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function show(int $id): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $item = (new JourFerieModel())
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->first();

        if ($item === null) {
            return $this->errorResponse('Jour férié introuvable.', 404);
        }

        return $this->successResponse($item);
    }

    public function create(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $validated = $this->validerDonnees($input);
        if ($validated instanceof ResponseInterface) {
            return $validated;
        }

        if ($this->dateDejaConfiguree($tenantId, $validated['date'])) {
            return $this->errorResponse('Un jour férié existe déjà à cette date.', 409);
        }

        $validated['tenant_id'] = $tenantId;
        $model = new JourFerieModel();
        if (! $model->insert($validated)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($model->find($model->getInsertID()), 'Jour férié créé.', 201);
    }

    public function update(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $model = new JourFerieModel();
        $existant = $model->where('id', $id)->where('tenant_id', $tenantId)->where('deleted_at', null)->first();
        if ($existant === null) {
            return $this->errorResponse('Jour férié introuvable.', 404);
        }

        $input = $this->jsonInput();
        $validated = $this->validerDonnees($input);
        if ($validated instanceof ResponseInterface) {
            return $validated;
        }

        if ($existant['date'] !== $validated['date'] && $this->dateDejaConfiguree($tenantId, $validated['date'], $id)) {
            return $this->errorResponse('Un jour férié existe déjà à cette date.', 409);
        }

        if (! $model->update($id, $validated)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($model->find($id), 'Jour férié mis à jour.');
    }

    public function delete(int $id): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $model = new JourFerieModel();
        $existant = $model->where('id', $id)->where('tenant_id', $tenantId)->where('deleted_at', null)->first();
        if ($existant === null) {
            return $this->errorResponse('Jour férié introuvable.', 404);
        }

        $model->delete($id);

        return $this->successResponse(null, 'Jour férié supprimé.');
    }

    public function importerBurundi(): ResponseInterface
    {
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }

        $input = $this->jsonInput();
        $annee = (int) ($input['annee'] ?? date('Y'));
        if ($annee < 2000 || $annee > 2100) {
            return $this->errorResponse('Année invalide.', 400);
        }

        $count = (new JourFerieService())->importerJoursFeriesBurundi($tenantId, $annee);

        return $this->successResponse(['count' => $count], 'Import terminé.');
    }

    /** @return array<string,mixed>|ResponseInterface */
    private function validerDonnees(array $input): array|ResponseInterface
    {
        $date = trim((string) ($input['date'] ?? ''));
        $nom = trim((string) ($input['nom'] ?? ''));
        $type = strtoupper((string) ($input['type'] ?? 'PERSONNALISE'));

        if (! $this->dateValide($date)) {
            return $this->errorResponse('La date est obligatoire et doit respecter le format AAAA-MM-JJ.', 400);
        }
        if ($nom === '' || mb_strlen($nom) > 150) {
            return $this->errorResponse('Le nom du jour férié est obligatoire.', 400);
        }
        if (! in_array($type, ['NATIONAL', 'RELIGIEUX', 'PERSONNALISE', 'CONGE'], true)) {
            return $this->errorResponse('Type de jour férié invalide.', 400);
        }
        foreach (['recurrent', 'travaille', 'actif'] as $flag) {
            if (array_key_exists($flag, $input) && ! in_array($input[$flag], [true, false, 0, 1, '0', '1'], true)) {
                return $this->errorResponse('Les options récurrent, travaillé et actif doivent être booléennes.', 400);
            }
        }
        $description = array_key_exists('description', $input) ? trim((string) $input['description']) : null;
        if ($description !== null && mb_strlen($description) > 2000) {
            return $this->errorResponse('La description ne peut pas dépasser 2000 caractères.', 400);
        }

        return [
            'date' => $date,
            'nom' => $nom,
            'type' => $type,
            'recurrent' => $this->valeurBooleenne($input['recurrent'] ?? false),
            'travaille' => $this->valeurBooleenne($input['travaille'] ?? false),
            'description' => $description,
            'actif' => $this->valeurBooleenne($input['actif'] ?? true),
        ];
    }

    private function dateValide(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function valeurBooleenne(mixed $value): int
    {
        return in_array($value, [true, 1, '1'], true) ? 1 : 0;
    }

    private function dateDejaConfiguree(int $tenantId, string $date, ?int $idExclu = null): bool
    {
        $requete = (new JourFerieModel())
            ->where('tenant_id', $tenantId)
            ->where('date', $date)
            ->where('deleted_at', null);
        if ($idExclu !== null) {
            $requete->where('id !=', $idExclu);
        }

        return $requete->first() !== null;
    }
}
