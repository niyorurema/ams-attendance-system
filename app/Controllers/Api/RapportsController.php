<?php

namespace App\Controllers\Api;

use App\Models\RapportPlanifieModel;
use App\Services\RapportService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * API de génération des rapports accessibles à l'utilisateur courant.
 */
class RapportsController extends BaseApiController
{
    /** Liste les employés visibles par l’utilisateur courant. */
    public function employesVisibles(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        try {
            $employes = (new RapportService())->getEmployesVisibles((int) $claims->sub);
            return $this->successResponse(['items' => $employes, 'total' => count($employes)]);
        } catch (Throwable $e) {
            log_message('error', 'Lecture des employés visibles impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de charger les employés visibles.', 500);
        }
    }

    /** Génère les données du rapport quotidien à la date demandée. */
    public function quotidien(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 400);
        }

        try {
            return $this->successResponse((new RapportService())->genererRapportQuotidien((int) $claims->sub, $date));
        } catch (Throwable $e) {
            log_message('error', 'Génération rapport quotidien impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de générer ce rapport.', 403);
        }
    }

    /** Télécharge le rapport quotidien au format PDF ou Excel. */
    public function export(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $date = (string) ($this->request->getGet('date') ?? date('Y-m-d'));
        $format = strtoupper((string) ($this->request->getGet('format') ?? 'PDF'));
        if (! $this->dateValide($date)) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 400);
        }
        if (! in_array($format, ['PDF', 'EXCEL'], true)) {
            return $this->errorResponse('Format invalide (PDF ou EXCEL).', 400);
        }

        try {
            $service = new RapportService();
            $rapport = $service->genererRapportQuotidien((int) $claims->sub, $date);
            $chemin = $format === 'PDF' ? $service->genererPDF($rapport) : $service->genererExcel($rapport);
            $extension = $format === 'PDF' ? 'pdf' : 'xlsx';
            $nomFichier = sprintf('rapport_%s.%s', $date, $extension);
            $reponse = $this->response->download($chemin, null, true);
            if ($reponse === null) {
                throw new \RuntimeException('Impossible de préparer le téléchargement.');
            }
            $reponse->setFileName($nomFichier);
            register_shutdown_function(static function () use ($chemin): void {
                if (is_file($chemin)) {
                    unlink($chemin);
                }
            });

            return $reponse;
        } catch (Throwable $e) {
            log_message('error', 'Export du rapport quotidien impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible de générer le fichier du rapport.', 500);
        }
    }

    /** Envoie le rapport quotidien à l’utilisateur courant ou à un destinataire autorisé. */
    public function envoyer(): ResponseInterface
    {
        $claims = $this->claims();
        if ($claims === null) {
            return $this->errorResponse('Jeton invalide ou expiré.', 401);
        }

        $input = $this->jsonInput();
        $date = (string) ($input['date'] ?? date('Y-m-d'));
        $format = strtoupper((string) ($input['format'] ?? 'PDF'));
        $langue = (string) ($input['langue'] ?? 'fr');
        $userId = isset($input['user_id']) ? filter_var($input['user_id'], FILTER_VALIDATE_INT) : (int) $claims->sub;

        if (! $this->dateValide($date)) {
            return $this->errorResponse('La date doit respecter le format AAAA-MM-JJ.', 400);
        }
        if (! in_array($format, ['PDF', 'EXCEL'], true)) {
            return $this->errorResponse('Format invalide (PDF ou EXCEL).', 400);
        }
        if (! in_array($langue, ['fr', 'en', 'rn'], true)) {
            return $this->errorResponse('Langue invalide.', 400);
        }
        if ($userId === false || (int) $userId < 1) {
            return $this->errorResponse('Destinataire invalide.', 400);
        }
        if ((int) $userId !== (int) $claims->sub && ! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis pour choisir un destinataire.', 403);
        }
        $tenantId = $this->tenantId();
        if ($tenantId === null || ! $this->utilisateurDansTenant((int) $userId, $tenantId)) {
            return $this->errorResponse('Destinataire introuvable pour ce tenant.', 400);
        }

        try {
            $service = new RapportService();
            $rapport = $service->genererRapportQuotidien((int) $userId, $date);
            if (! $service->envoyerEmail((int) $userId, $rapport, $format, $langue)) {
                return $this->errorResponse('L’envoi du rapport par e-mail a échoué.', 502);
            }

            return $this->successResponse(['envoye' => true], 'Rapport envoyé par e-mail.');
        } catch (Throwable $e) {
            log_message('error', 'Envoi du rapport quotidien impossible : {error}', ['error' => $e->getMessage()]);
            return $this->errorResponse('Impossible d’envoyer ce rapport.', 500);
        }
    }

    /** Lit la configuration unique du rapport J-1 pour le tenant courant. */
    public function configurationJournaliere(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $db = Database::connect();
        $config = $db->table('rapport_journalier_configs')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();
        $destinataires = $db->table('rapport_journalier_destinataires_unites d')
                ->select('d.user_id, d.unite_id, u.nom_complet, u.email, o.nom AS unite_nom')
                ->join('users u', 'u.id = d.user_id AND u.tenant_id = d.tenant_id', 'inner')
                ->join('unites_organisationnelles o', 'o.id = d.unite_id AND o.tenant_id = d.tenant_id', 'left')
                ->where('d.tenant_id', $tenantId)
                ->orderBy('o.nom', 'ASC')
                ->orderBy('u.nom_complet', 'ASC')
                ->get()
                ->getResultArray();
        foreach ($destinataires as &$destinataire) {
            $destinataire['unite_id'] = $destinataire['unite_id'] === null
                ? null
                : (int) $destinataire['unite_id'];
        }
        unset($destinataire);
        $tenant = $db->table('tenants')
            ->select('jours_travailles')
            ->where('id', $tenantId)
            ->get()
            ->getRowArray();

        return $this->successResponse([
            'configuration' => $config ?? [
                'heure_envoi' => '08:00:00',
                'format' => 'PDF',
                'langue' => 'fr',
                'actif' => 1,
            ],
            'destinataires' => $destinataires,
            'jours_travailles' => \App\Services\JourTravailleService::normaliserJours($tenant['jours_travailles'] ?? null),
        ]);
    }

    /** Enregistre la configuration journalière et ses destinataires supplémentaires. */
    public function enregistrerConfigurationJournaliere(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $input = $this->jsonInput();
        $heure = (string) ($input['heure_envoi'] ?? '');
        $format = strtoupper((string) ($input['format'] ?? 'PDF'));
        $langue = (string) ($input['langue'] ?? 'fr');
        $actif = filter_var($input['actif'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $assignations = $input['destinataires'] ?? [];
        if (! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $heure)
            || ! in_array($format, ['PDF', 'EXCEL'], true)
            || ! in_array($langue, ['fr', 'en', 'rn'], true)
            || $actif === null
            || ! is_array($assignations)
        ) {
            return $this->errorResponse('Configuration du rapport journalier invalide.', 400);
        }

        $destinataires = [];
        foreach ($assignations as $assignation) {
            if (! is_array($assignation)) {
                return $this->errorResponse('Une affectation de destinataire est invalide.', 400);
            }
            $userId = filter_var($assignation['user_id'] ?? null, FILTER_VALIDATE_INT);
            $uniteId = ($assignation['unite_id'] ?? null) === null || ($assignation['unite_id'] ?? '') === ''
                ? null
                : filter_var($assignation['unite_id'], FILTER_VALIDATE_INT);
            if ($userId === false || $userId < 1 || ($uniteId !== null && ($uniteId === false || $uniteId < 1))) {
                return $this->errorResponse('Une affectation contient un utilisateur ou une unité invalide.', 400);
            }
            $destinataires[] = [
                'user_id' => (int) $userId,
                'unite_id' => $uniteId === null ? null : (int) $uniteId,
            ];
        }
        $clesDestinataires = [];
        $destinataires = array_values(array_filter($destinataires, static function (array $destinataire) use (&$clesDestinataires): bool {
            $cle = $destinataire['user_id'] . ':' . ($destinataire['unite_id'] ?? 'global');
            if (isset($clesDestinataires[$cle])) {
                return false;
            }
            $clesDestinataires[$cle] = true;
            return true;
        }));
        $destinataireIds = array_values(array_unique(array_column($destinataires, 'user_id')));
        if ($destinataireIds !== []) {
            $utilisateursValides = Database::connect()->table('users')
                ->select('id, email')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->where('deleted_at', null)
                ->whereIn('id', $destinataireIds)
                ->get()
                ->getResultArray();
            if (count($utilisateursValides) !== count($destinataireIds)
                || count(array_filter(
                    $utilisateursValides,
                    static fn (array $user): bool => filter_var($user['email'] ?? null, FILTER_VALIDATE_EMAIL) !== false
                )) !== count($destinataireIds)
            ) {
                return $this->errorResponse('Un destinataire est inactif, hors de ce tenant ou sans adresse e-mail valide.', 400);
            }
        }
        $uniteIds = array_values(array_unique(array_filter(array_column($destinataires, 'unite_id'), static fn (?int $id): bool => $id !== null)));
        if ($uniteIds !== []) {
            $unitesValides = Database::connect()->table('unites_organisationnelles')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->whereIn('id', $uniteIds)
                ->get()
                ->getResultArray();
            if (count($unitesValides) !== count($uniteIds)) {
                return $this->errorResponse('Une ou plusieurs unités sont inactives ou hors de ce tenant.', 400);
            }
        }

        $db = Database::connect();
        $maintenant = date('Y-m-d H:i:s');
        try {
            $db->transStart();
            $existant = $db->table('rapport_journalier_configs')
                ->where('tenant_id', $tenantId)
                ->get()
                ->getRowArray();
            $configData = [
                'heure_envoi' => strlen($heure) === 5 ? $heure . ':00' : $heure,
                'format' => $format,
                'langue' => $langue,
                'actif' => $actif ? 1 : 0,
                'updated_at' => $maintenant,
            ];
            if ($existant === null) {
                $configData['tenant_id'] = $tenantId;
                $configData['created_at'] = $maintenant;
                $db->table('rapport_journalier_configs')->insert($configData);
            } else {
                $db->table('rapport_journalier_configs')
                    ->where('tenant_id', $tenantId)
                    ->update($configData);
            }

            $db->table('rapport_journalier_destinataires_unites')
                ->where('tenant_id', $tenantId)
                ->delete();
            if ($destinataires !== []) {
                $db->table('rapport_journalier_destinataires_unites')->insertBatch(array_map(
                    static fn (array $destinataire): array => [
                        'tenant_id' => $tenantId,
                        'user_id' => $destinataire['user_id'],
                        'unite_id' => $destinataire['unite_id'],
                        'created_at' => $maintenant,
                    ],
                    $destinataires
                ));
            }
            $db->transComplete();
            if (! $db->transStatus()) {
                throw new \RuntimeException('La transaction de configuration a échoué.');
            }
        } catch (Throwable $e) {
            log_message('error', 'Enregistrement de la configuration du rapport journalier impossible : {error}', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Impossible d’enregistrer la configuration du rapport journalier.', 500);
        }

        return $this->configurationJournaliere();
    }

    /** Liste les rapports archivés accessibles à l’utilisateur courant. */
    public function historique(): ResponseInterface
    {
        $claims = $this->claims();
        $tenantId = $this->tenantId();
        if ($claims === null || $tenantId === null) {
            return $this->authorizationError();
        }
        $limit = filter_var($this->request->getGet('limit') ?? 20, FILTER_VALIDATE_INT);
        $offset = filter_var($this->request->getGet('offset') ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100 || $offset === false || $offset < 0) {
            return $this->errorResponse('Pagination invalide.', 400);
        }

        $query = Database::connect()->table('rapports_envoyes r')
            ->select('r.id, r.user_id, r.date_rapport, r.heure_envoi, r.format, r.destinataire_email, r.destinataire_nom, r.statut, r.message_erreur, r.contenu_json, u.nom_complet AS compte_destinataire')
            ->join('users u', 'u.id = r.user_id AND u.tenant_id = r.tenant_id', 'left')
            ->where('r.tenant_id', $tenantId);
        if (! $this->canManage()) {
            $query->where('r.user_id', (int) $claims->sub);
        }
        $total = (clone $query)->countAllResults();
        $items = $query->orderBy('r.heure_envoi', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();
        foreach ($items as &$item) {
            $item['contenu_disponible'] = $item['contenu_json'] !== null;
            unset($item['contenu_json']);
            $item['destinataire_nom'] = $item['destinataire_nom'] ?: $item['compte_destinataire'];
            unset($item['compte_destinataire']);
        }
        unset($item);

        return $this->successResponse(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    /** Ouvre un rapport archivé sans permettre de sortir du tenant ou de son propriétaire. */
    public function historiqueDetail(int $id): ResponseInterface
    {
        $claims = $this->claims();
        $tenantId = $this->tenantId();
        if ($claims === null || $tenantId === null) {
            return $this->authorizationError();
        }

        $query = Database::connect()->table('rapports_envoyes r')
            ->select('r.id, r.user_id, r.date_rapport, r.heure_envoi, r.format, r.destinataire_email, r.destinataire_nom, r.statut, r.message_erreur, r.contenu_json')
            ->where('r.id', $id)
            ->where('r.tenant_id', $tenantId);
        if (! $this->canManage()) {
            $query->where('r.user_id', (int) $claims->sub);
        }
        $rapport = $query->get()->getRowArray();
        if ($rapport === null) {
            return $this->errorResponse('Rapport archivé introuvable.', 404);
        }
        try {
            $contenu = $rapport['contenu_json'] === null
                ? null
                : json_decode((string) $rapport['contenu_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            log_message('error', 'Contenu de rapport archivé invalide pour l’envoi {id} : {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Le contenu de ce rapport archivé est illisible.', 500);
        }
        unset($rapport['contenu_json']);

        return $this->successResponse(['envoi' => $rapport, 'contenu' => $contenu]);
    }

    /** Liste les planifications du tenant courant. */
    public function planifies(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $items = Database::connect()->table('rapports_planifies rp')
            ->select('rp.id, rp.tenant_id, rp.user_id, u.nom_complet AS destinataire_nom, u.email AS destinataire_email, rp.nom, rp.type, rp.heure_envoi, rp.jours_semaine, rp.format, rp.langue, rp.perimetre_type, rp.perimetre_unite_id, rp.inclure_agents_bureau, rp.actif, rp.dernier_envoi, rp.created_at, rp.updated_at')
            ->join('users u', 'u.id = rp.user_id AND u.tenant_id = rp.tenant_id', 'left')
            ->where('rp.tenant_id', $tenantId)
            ->where('rp.deleted_at', null)
            ->orderBy('rp.nom', 'ASC')
            ->get()
            ->getResultArray();

        return $this->successResponse(['items' => $items, 'total' => count($items)]);
    }

    /** Crée une planification appartenant au tenant courant. */
    public function creerPlanifie(): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $input = $this->jsonInput();
        $userId = isset($input['user_id'])
            ? filter_var($input['user_id'], FILTER_VALIDATE_INT)
            : (int) ($this->claims()?->sub ?? 0);
        if ($userId === false || ! $this->utilisateurDansTenant((int) $userId, $tenantId)) {
            return $this->errorResponse('Destinataire introuvable pour ce tenant.', 400);
        }
        if (! $this->perimetreValide($input, $tenantId)) {
            return $this->errorResponse('Unité de périmètre invalide pour ce tenant.', 400);
        }

        $data = $this->champsPlanification($input);
        $data['tenant_id'] = $tenantId;
        $data['user_id'] = (int) $userId;
        $model = new RapportPlanifieModel();
        if (! $model->insert($data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($model->find((int) $model->getInsertID()), 'Rapport planifié créé.', 201);
    }

    /** Met à jour une planification appartenant au tenant courant. */
    public function modifierPlanifie(int $id): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $model = new RapportPlanifieModel();
        $existant = $model->where('tenant_id', $tenantId)->find($id);
        if ($existant === null) {
            return $this->errorResponse('Rapport planifié introuvable.', 404);
        }

        $input = $this->jsonInput();
        $userId = isset($input['user_id']) ? filter_var($input['user_id'], FILTER_VALIDATE_INT) : (int) $existant['user_id'];
        if ($userId === false || ! $this->utilisateurDansTenant((int) $userId, $tenantId)) {
            return $this->errorResponse('Destinataire introuvable pour ce tenant.', 400);
        }
        if (! $this->perimetreValide($input, $tenantId)) {
            return $this->errorResponse('Unité de périmètre invalide pour ce tenant.', 400);
        }

        $data = $this->champsPlanification($input);
        if ($data === []) {
            return $this->errorResponse('Aucun champ modifiable fourni.', 400);
        }
        $data['user_id'] = (int) $userId;
        if (! $model->update($id, $data)) {
            return $this->errorResponse(implode(' ', $model->errors()), 422);
        }

        return $this->successResponse($model->where('tenant_id', $tenantId)->find($id), 'Rapport planifié mis à jour.');
    }

    /** Supprime une planification appartenant au tenant courant. */
    public function supprimerPlanifie(int $id): ResponseInterface
    {
        $tenantId = $this->tenantId();
        if ($tenantId === null) {
            return $this->authorizationError();
        }
        if (! $this->canManage()) {
            return $this->errorResponse('Droits d’administration requis.', 403);
        }

        $model = new RapportPlanifieModel();
        if ($model->where('tenant_id', $tenantId)->find($id) === null) {
            return $this->errorResponse('Rapport planifié introuvable.', 404);
        }

        $model->delete($id);

        return $this->successResponse(null, 'Rapport planifié supprimé.');
    }

    /** @param array<string, mixed> $input */
    private function champsPlanification(array $input): array
    {
        $champs = array_intersect_key($input, array_flip([
            'nom', 'type', 'heure_envoi', 'jours_semaine', 'format', 'langue',
            'perimetre_type', 'perimetre_unite_id', 'inclure_agents_bureau', 'actif',
        ]));
        foreach (['inclure_agents_bureau', 'actif'] as $champ) {
            if (array_key_exists($champ, $champs)) {
                $champs[$champ] = filter_var($champs[$champ], FILTER_VALIDATE_BOOL) ? 1 : 0;
            }
        }

        return $champs;
    }

    /** @param array<string, mixed> $input */
    private function perimetreValide(array $input, int $tenantId): bool
    {
        if (empty($input['perimetre_unite_id'])) {
            return true;
        }

        $uniteId = filter_var($input['perimetre_unite_id'], FILTER_VALIDATE_INT);
        return $uniteId !== false && Database::connect()->table('unites_organisationnelles')
            ->where('id', (int) $uniteId)
            ->where('tenant_id', $tenantId)
            ->countAllResults() > 0;
    }

    private function utilisateurDansTenant(int $userId, int $tenantId): bool
    {
        return Database::connect()->table('users')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    private function dateValide(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
