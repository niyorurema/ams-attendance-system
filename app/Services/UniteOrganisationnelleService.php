<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Fournit l'accès à la hiérarchie des unités organisationnelles.
 *
 * Toutes les recherches sont isolées par tenant et ignorent les unités ou
 * employés désactivés. Les méthodes protégées peuvent être redéfinies dans
 * les tests pour vérifier la récursion sans dépendre d'une base de données.
 */
class UniteOrganisationnelleService
{
    /** Connexion à la base de données applicative. */
    protected BaseConnection $db;

    /**
     * @param ?BaseConnection $db Connexion injectée, utile pour les tests.
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Retourne toutes les unités actives situées sous une unité donnée.
     *
     * L'unité de départ n'est pas incluse dans le résultat. Une protection
     * contre les cycles empêche une hiérarchie corrompue de boucler.
     *
     * @param int $uniteId Identifiant de l'unité racine.
     * @return array<int, array<string, mixed>> Descendants, ordonnés par parcours hiérarchique.
     */
    public function getUnitesDescendantes(int $uniteId): array
    {
        $unite = $this->trouverUniteActive($uniteId);
        if ($unite === null) {
            return [];
        }

        $descendantes = [];
        $visitees = [$uniteId => true];
        $this->collecterDescendantes($uniteId, (int) $unite['tenant_id'], $descendantes, $visitees);

        return $descendantes;
    }

    /**
     * Construit l'arbre des unités actives d'un tenant.
     *
     * Chaque nœud possède une clé `enfants`, y compris les feuilles.
     *
     * @param int $tenantId Identifiant du tenant.
     * @return array<int, array<string, mixed>> Racines de l'arbre.
     */
    public function getArbreHierarchique(int $tenantId): array
    {
        $unites = $this->trouverUnitesActivesDuTenant($tenantId);
        $parId = [];

        foreach ($unites as $unite) {
            $unite['enfants'] = [];
            $parId[(int) $unite['id']] = $unite;
        }

        $enfantsParParent = [];
        foreach ($parId as $uniteId => $unite) {
            $parentId = $unite['parent_id'] === null ? null : (int) $unite['parent_id'];
            if ($parentId !== null && $parentId !== $uniteId && isset($parId[$parentId])) {
                $enfantsParParent[$parentId][] = $uniteId;
            }
        }

        $construireNoeud = function (int $uniteId) use (&$construireNoeud, $parId, $enfantsParParent): array {
            $noeud = $parId[$uniteId];
            foreach ($enfantsParParent[$uniteId] ?? [] as $enfantId) {
                $noeud['enfants'][] = $construireNoeud($enfantId);
            }

            return $noeud;
        };

        $racines = [];
        foreach ($parId as $uniteId => $unite) {
            $parentId = $unite['parent_id'] === null ? null : (int) $unite['parent_id'];
            if ($parentId === null || $parentId === $uniteId || ! isset($parId[$parentId])) {
                $racines[] = $construireNoeud($uniteId);
            }
        }

        return $racines;
    }

    /**
     * Retourne les employés actifs d'une unité, éventuellement de ses descendants.
     *
     * @param int $uniteId Identifiant de l'unité recherchée.
     * @param bool $recursif Inclut les unités descendantes si vrai.
     * @return array<int, array<string, mixed>> Employés actifs.
     */
    public function getEmployesDeUnite(int $uniteId, bool $recursif = true): array
    {
        $unite = $this->trouverUniteActive($uniteId);
        if ($unite === null) {
            return [];
        }

        $uniteIds = [$uniteId];
        if ($recursif) {
            foreach ($this->getUnitesDescendantes($uniteId) as $descendante) {
                $uniteIds[] = (int) $descendante['id'];
            }
        }

        return $this->trouverEmployesActifs((int) $unite['tenant_id'], array_values(array_unique($uniteIds)));
    }

    /**
     * Collecte récursivement les enfants d'une unité.
     *
     * @param array<int, array<string, mixed>> $descendantes
     * @param array<int, bool> $visitees
     */
    protected function collecterDescendantes(int $parentId, int $tenantId, array &$descendantes, array &$visitees): void
    {
        foreach ($this->trouverEnfantsActifs($parentId, $tenantId) as $enfant) {
            $enfantId = (int) $enfant['id'];
            if (isset($visitees[$enfantId])) {
                continue;
            }

            $visitees[$enfantId] = true;
            $descendantes[] = $enfant;
            $this->collecterDescendantes($enfantId, $tenantId, $descendantes, $visitees);
        }
    }

    /**
     * Recherche une unité active par son identifiant.
     *
     * @return ?array<string, mixed>
     */
    protected function trouverUniteActive(int $uniteId): ?array
    {
        $unite = $this->db->table('unites_organisationnelles')
            ->where('id', $uniteId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $unite ?: null;
    }

    /**
     * Recherche les enfants actifs directs, dans l'ordre d'affichage.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function trouverEnfantsActifs(int $parentId, int $tenantId): array
    {
        return $this->db->table('unites_organisationnelles')
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $parentId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('ordre_affichage', 'ASC')
            ->orderBy('nom', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Recherche toutes les unités actives d'un tenant, dans un ordre stable.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function trouverUnitesActivesDuTenant(int $tenantId): array
    {
        return $this->db->table('unites_organisationnelles')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('niveau', 'ASC')
            ->orderBy('ordre_affichage', 'ASC')
            ->orderBy('nom', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Recherche les employés actifs des unités indiquées.
     *
     * @param array<int, int> $uniteIds
     * @return array<int, array<string, mixed>>
     */
    protected function trouverEmployesActifs(int $tenantId, array $uniteIds): array
    {
        if ($uniteIds === []) {
            return [];
        }

        return $this->db->table('employes')
            ->where('tenant_id', $tenantId)
            ->whereIn('unite_id', $uniteIds)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->orderBy('nom', 'ASC')
            ->orderBy('prenom', 'ASC')
            ->get()
            ->getResultArray();
    }
}
