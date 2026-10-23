<?php

namespace App\Services;

use App\Libraries\ZKTecoPullClient;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Service de gestion des affectations employés ↔ capteurs ZKTeco.
 *
 * Le service centralise les opérations de déplacement, activation, désactivation
 * et synchronisation des mappings sur les appareils. L'objectif est de garder
 * la logique de communication réseau dans un seul point d'entrée, tout en
 * maintenant une base de données cohérente dès qu'une écriture sur un capteur
 * est validée.
 */
class AffectationService
{
    /** Connexion à la base de données applicative. */
    protected ?BaseConnection $db = null;

    /**
     * @param ?BaseConnection $db Connexion injectée, utile pour les tests.
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
    }

    /**
     * Retourne la connexion active, en la créant à la demande.
     */
    protected function getDb(): BaseConnection
    {
        if ($this->db === null) {
            $this->db = Database::connect();
        }

        return $this->db;
    }

    /**
     * Déplace un employé d'un capteur source vers un capteur de destination.
     *
     * La méthode applique d'abord la suppression du mapping source puis la
     * création ou la mise à jour du mapping destination. L'opération est
     * réalisée avec une seule connexion par capteur, et l'état de la base est
     * mis à jour même si la synchronisation distante partiellement échoue.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param int $capteurSourceId Capteur d'origine.
     * @param int $capteurDestId Capteur de destination.
     * @param array<string, mixed> $options Options complémentaires : badge_id, user_id, name, password, role, card_no.
     * @return array<string, mixed> Résultat détaillé de la synchronisation.
     */
    public function deplacerEmploye(int $employeId, int $capteurSourceId, int $capteurDestId, array $options = []): array
    {
        $employe = $this->trouverEmployeParId($employeId);
        if ($employe === null) {
            return [
                'success' => false,
                'error' => sprintf('Employé %d introuvable.', $employeId),
                'operations' => [],
            ];
        }

        if ($capteurSourceId === $capteurDestId) {
            return [
                'success' => false,
                'error' => 'Le capteur source et le capteur destination sont identiques.',
                'operations' => [],
            ];
        }

        $sourceMapping = $this->getAffectationActif($employeId, $capteurSourceId);
        $destMapping = $this->getAffectationActif($employeId, $capteurDestId);

        $requestedBadgeId = isset($options['badge_id']) ? (int) $options['badge_id'] : null;
        $sourceBadgeId = $requestedBadgeId ?? (int) ($sourceMapping['badge_id'] ?? $this->genererBadgeId($capteurSourceId));
        $destBadgeId = $requestedBadgeId ?? (int) ($destMapping['badge_id'] ?? $sourceBadgeId ?? $this->genererBadgeId($capteurDestId));

        if ($sourceMapping !== null && $requestedBadgeId === null) {
            $sourceBadgeId = $destBadgeId;
        }

        $operations = [];

        if ($sourceMapping !== null) {
            $operations[] = [
                'capteur_id' => $capteurSourceId,
                'type' => 'remove_user',
                'uid' => $sourceBadgeId,
            ];
        }

        $operations[] = [
            'capteur_id' => $capteurDestId,
            'type' => 'set_user',
            'uid' => $destBadgeId,
            'user_id' => (string) ($options['user_id'] ?? $employe['badge_id'] ?? $destBadgeId),
            'name' => (string) ($options['name'] ?? ($employe['nom'] ?? 'EMPLOYE') . ' ' . ($employe['prenom'] ?? '')),
            'password' => (string) ($options['password'] ?? ''),
            'role' => (int) ($options['role'] ?? 0),
            'card_no' => (string) ($options['card_no'] ?? ''),
        ];

        $results = [];
        $success = true;

        foreach ($operations as $operation) {
            $capteurId = (int) $operation['capteur_id'];
            $result = $this->synchroniserEcritureCapteur($capteurId, [$operation]);
            $results[] = $result;

            if (($result['success'] ?? false) !== true) {
                $success = false;
            }
        }

        if ($sourceMapping !== null) {
            $this->enregistrerAffectation($employeId, $capteurSourceId, $sourceBadgeId, false);
        }

        $this->enregistrerAffectation($employeId, $capteurDestId, $destBadgeId, true);

        return [
            'success' => $success,
            'employe_id' => $employeId,
            'source_capteur_id' => $capteurSourceId,
            'destination_capteur_id' => $capteurDestId,
            'badge_id' => $destBadgeId,
            'operations' => array_values($operations),
            'results' => $results,
        ];
    }

    /**
     * Désactive tous les mappings actifs d'un employé sur les capteurs.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param array<string, mixed> $options options permettant de forcer un état ou un message contextuel.
     * @return array<string, mixed> Résultat détaillé.
     */
    public function desactiverEmploye(int $employeId, array $options = []): array
    {
        $mappings = $this->getAffectationsActivesEmploye($employeId);
        if ($mappings === []) {
            return [
                'success' => false,
                'employe_id' => $employeId,
                'operations' => [],
                'message' => 'Aucune affectation active à désactiver.',
            ];
        }

        $operations = [];
        foreach ($mappings as $mapping) {
            $operations[] = [
                'capteur_id' => (int) $mapping['capteur_id'],
                'type' => 'remove_user',
                'uid' => (int) $mapping['badge_id'],
            ];
        }

        $results = [];
        $success = true;
        foreach ($operations as $operation) {
            $result = $this->synchroniserEcritureCapteur((int) $operation['capteur_id'], [$operation]);
            $results[] = $result;
            if (($result['success'] ?? false) !== true) {
                $success = false;
            }
            $this->enregistrerAffectation($employeId, (int) $operation['capteur_id'], (int) $operation['uid'], false);
        }

        $raison = trim((string) ($options['raison'] ?? ''));
        if ($raison !== '') {
            $this->persistMotifDesactivation($employeId, $raison);
        }

        return [
            'success' => $success,
            'employe_id' => $employeId,
            'operations' => $operations,
            'results' => $results,
        ];
    }

    protected function persistMotifDesactivation(int $employeId, ?string $raison): void
    {
        $raison = trim((string) ($raison ?? ''));
        if ($raison === '') {
            return;
        }

        $employe = $this->getDb()->table('employes')
            ->select('notes')
            ->where('id', $employeId)
            ->get()
            ->getRowArray();

        $notesExistantes = trim((string) ($employe['notes'] ?? ''));
        $notes = $notesExistantes === '' ? $raison : $notesExistantes . "\n" . $raison;

        $this->getDb()->table('employes')->where('id', $employeId)->update(['notes' => $notes]);
    }

    /**
     * Réactive un employé sur les capteurs où il est connu mais désactivé.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param array<string, mixed> $options Options de remise en service.
     * @return array<string, mixed> Résultat détaillé.
     */
    public function reactiverEmploye(int $employeId, array $options = []): array
    {
        $mappings = $this->getAffectationsInactivesEmploye($employeId);
        if ($mappings === []) {
            return [
                'success' => false,
                'employe_id' => $employeId,
                'operations' => [],
                'message' => 'Aucune affectation inactive à réactiver.',
            ];
        }

        $results = [];
        $operations = [];
        $success = true;

        foreach ($mappings as $mapping) {
            $badgeId = (int) $mapping['badge_id'];
            $operation = [
                'capteur_id' => (int) $mapping['capteur_id'],
                'type' => 'set_user',
                'uid' => $badgeId,
                'user_id' => (string) ($options['user_id'] ?? $badgeId),
                'name' => (string) ($options['name'] ?? 'EMPLOYE'),
                'password' => (string) ($options['password'] ?? ''),
                'role' => (int) ($options['role'] ?? 0),
                'card_no' => (string) ($options['card_no'] ?? ''),
            ];
            $operations[] = $operation;

            $result = $this->synchroniserEcritureCapteur((int) $mapping['capteur_id'], [$operation]);
            $results[] = $result;
            if (($result['success'] ?? false) !== true) {
                $success = false;
            }

            $this->enregistrerAffectation($employeId, (int) $mapping['capteur_id'], $badgeId, true);
        }

        return [
            'success' => $success,
            'employe_id' => $employeId,
            'operations' => $operations,
            'results' => $results,
        ];
    }

    /**
     * Ajoute une affectation employé ↔ capteur.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param int $capteurId Identifiant du capteur.
     * @param ?int $badgeId Badge ZKTeco à utiliser. Si null, le prochain identifiant libre est généré.
     * @return array<string, mixed> Résultat détaillé.
     */
    public function ajouterEmployeCapteur(int $employeId, int $capteurId, ?int $badgeId = null): array
    {
        $employe = $this->trouverEmployeParId($employeId);
        if ($employe === null) {
            return [
                'success' => false,
                'error' => sprintf('Employé %d introuvable.', $employeId),
                'operations' => [],
            ];
        }

        $badge = $badgeId ?? $this->genererBadgeId($capteurId);
        $operation = [
            'capteur_id' => $capteurId,
            'type' => 'set_user',
            'uid' => $badge,
            'user_id' => (string) ($employe['badge_id'] ?? $badge),
            'name' => (string) (($employe['nom'] ?? 'EMPLOYE') . ' ' . ($employe['prenom'] ?? '')),
            'password' => '',
            'role' => 0,
            'card_no' => '',
        ];

        $result = $this->synchroniserEcritureCapteur($capteurId, [$operation]);
        $this->enregistrerAffectation($employeId, $capteurId, $badge, true);

        return [
            'success' => ($result['success'] ?? false),
            'employe_id' => $employeId,
            'capteur_id' => $capteurId,
            'badge_id' => $badge,
            'operations' => [$operation],
            'result' => $result,
        ];
    }

    /**
     * Retire un employé d'un capteur donné.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param int $capteurId Capteur concerné.
     * @return array<string, mixed> Résultat détaillé.
     */
    public function retirerEmployeCapteur(int $employeId, int $capteurId): array
    {
        $mapping = $this->getAffectationActif($employeId, $capteurId);
        if ($mapping === null) {
            return [
                'success' => false,
                'employe_id' => $employeId,
                'capteur_id' => $capteurId,
                'operations' => [],
                'message' => 'Aucune affectation active à retirer.',
            ];
        }

        $operation = [
            'capteur_id' => $capteurId,
            'type' => 'remove_user',
            'uid' => (int) $mapping['badge_id'],
        ];

        $result = $this->synchroniserEcritureCapteur($capteurId, [$operation]);
        $this->enregistrerAffectation($employeId, $capteurId, (int) $mapping['badge_id'], false);

        return [
            'success' => ($result['success'] ?? false),
            'employe_id' => $employeId,
            'capteur_id' => $capteurId,
            'operations' => [$operation],
            'result' => $result,
        ];
    }

    /**
     * Synchronise les affectations actives d'un capteur.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return array<string, mixed> Résultat détaillé.
     */
    public function syncAffectationsCapteur(int $capteurId): array
    {
        $mappings = $this->getAffectationsCapteur($capteurId);
        if ($mappings === []) {
            return [
                'success' => false,
                'capteur_id' => $capteurId,
                'operations' => [],
                'message' => 'Aucune affectation pour ce capteur.',
            ];
        }

        $operations = [];
        foreach ($mappings as $mapping) {
            $operations[] = [
                'capteur_id' => $capteurId,
                'type' => 'set_user',
                'uid' => (int) $mapping['badge_id'],
                'user_id' => (string) ($mapping['badge_id'] ?? ''),
                'name' => 'EMPLOYE',
                'password' => '',
                'role' => 0,
                'card_no' => '',
            ];
        }

        $result = $this->synchroniserEcritureCapteur($capteurId, $operations);

        return [
            'success' => ($result['success'] ?? false),
            'capteur_id' => $capteurId,
            'operations' => $operations,
            'result' => $result,
        ];
    }

    /**
     * Retourne les capteurs actifs associés à un employé.
     *
     * @param int $employeId Identifiant de l'employé.
     * @return array<int, array<string, mixed>> Liste des affectations valides.
     */
    public function getCapteursEmploye(int $employeId): array
    {
        $mappings = $this->getAffectationsActivesEmploye($employeId);
        $capteurs = [];

        foreach ($mappings as $mapping) {
            $capteur = $this->trouverCapteurParId((int) $mapping['capteur_id']);
            if ($capteur !== null) {
                $capteurs[] = $capteur;
            }
        }

        return $capteurs;
    }

    /**
     * Génère le prochain badge libre pour un capteur.
     *
     * @param int $capteurId Identifiant du capteur.
     * @return int Prochain badge disponible.
     */
    public function genererBadgeId(int $capteurId): int
    {
        $existing = $this->getAffectationsCapteur($capteurId);
        $badgeIds = [];

        foreach ($existing as $mapping) {
            $badgeIds[] = (int) $mapping['badge_id'];
        }

        if ($badgeIds === []) {
            return 1000;
        }

        return max($badgeIds) + 1;
    }

    /**
     * Recherche un employé par identifiant.
     *
     * @return ?array<string, mixed>
     */
    protected function trouverEmployeParId(int $employeId): ?array
    {
        return $this->getDb()->table('employes')
            ->where('id', $employeId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
    }

    /**
     * Recherche un capteur par identifiant.
     *
     * @return ?array<string, mixed>
     */
    protected function trouverCapteurParId(int $capteurId): ?array
    {
        return $this->getDb()->table('capteurs')
            ->where('id', $capteurId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
    }

    /**
     * Retourne les affectations actives d'un employé.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAffectationsActivesEmploye(int $employeId): array
    {
        return $this->getDb()->table('employe_capteur')
            ->where('employe_id', $employeId)
            ->where('actif', 1)
            ->get()
            ->getResultArray();
    }

    /**
     * Retourne les affectations inactives d'un employé.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAffectationsInactivesEmploye(int $employeId): array
    {
        return $this->getDb()->table('employe_capteur')
            ->where('employe_id', $employeId)
            ->where('actif', 0)
            ->get()
            ->getResultArray();
    }

    /**
     * Retourne l'affectation active d'un employé sur un capteur précis.
     *
     * @return ?array<string, mixed>
     */
    protected function getAffectationActif(int $employeId, int $capteurId): ?array
    {
        return $this->getDb()->table('employe_capteur')
            ->where('employe_id', $employeId)
            ->where('capteur_id', $capteurId)
            ->where('actif', 1)
            ->get()
            ->getRowArray();
    }

    /**
     * Retourne toutes les affectations d'un employé.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAffectationsEmploye(int $employeId): array
    {
        return $this->getDb()->table('employe_capteur')
            ->where('employe_id', $employeId)
            ->orderBy('capteur_id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Retourne toutes les affectations d'un capteur.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getAffectationsCapteur(int $capteurId): array
    {
        return $this->getDb()->table('employe_capteur')
            ->where('capteur_id', $capteurId)
            ->orderBy('badge_id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Enregistre l'état d'une affectation dans la table de jointure.
     *
     * @param int $employeId Identifiant de l'employé.
     * @param int $capteurId Identifiant du capteur.
     * @param int $badgeId Badge ZKTeco.
     * @param bool $actif Indique si la relation est active.
     */
    protected function enregistrerAffectation(int $employeId, int $capteurId, int $badgeId, bool $actif): void
    {
        $employe = $this->trouverEmployeParId($employeId);
        $tenantId = (int) ($employe['tenant_id'] ?? 0);

        $existante = $this->getDb()->table('employe_capteur')
            ->where('employe_id', $employeId)
            ->where('capteur_id', $capteurId)
            ->get()
            ->getRowArray();

        $payload = [
            'tenant_id' => $tenantId,
            'employe_id' => $employeId,
            'capteur_id' => $capteurId,
            'badge_id' => $badgeId,
            'actif' => $actif ? 1 : 0,
            'date_affectation' => date('Y-m-d'),
            'date_retrait' => $actif ? null : date('Y-m-d'),
            'sync_status' => 'SYNC',
            'derniere_sync' => date('Y-m-d H:i:s'),
        ];

        if ($existante !== null) {
            $this->getDb()->table('employe_capteur')
                ->where('id', (int) $existante['id'])
                ->update($payload);

            return;
        }

        $this->getDb()->table('employe_capteur')->insert($payload);
    }

    /**
     * Synchronise les opérations d'écriture sur un capteur unique.
     *
     * Les écrans ZKTeco demandent un passage en mode "désactivé" avant les
     * modifications de données, puis une réactivation à la fin.
     *
     * @param int $capteurId Identifiant du capteur.
     * @param array<int, array<string, mixed>> $operations Opérations unitaires à exécuter.
     * @return array<string, mixed> Résultat détaillé.
     */
    protected function synchroniserEcritureCapteur(int $capteurId, array $operations): array
    {
        $capteur = $this->trouverCapteurParId($capteurId);
        if ($capteur === null) {
            return [
                'success' => false,
                'capteur_id' => $capteurId,
                'error' => 'Capteur introuvable.',
                'operations' => $operations,
            ];
        }

        $client = new ZKTecoPullClient(
            (string) $capteur['adresse_ip'],
            (int) $capteur['port'],
            true,
            (int) ($capteur['timeout_secondes'] ?? 60),
            (int) ($capteur['com_key'] ?? 0)
        );

        $result = [
            'success' => true,
            'capteur_id' => $capteurId,
            'operations' => [],
        ];

        try {
            $client->connect();
            $client->disableDevice();

            foreach ($operations as $operation) {
                $type = (string) ($operation['type'] ?? '');
                $uid = (int) ($operation['uid'] ?? 0);
                $details = [
                    'type' => $type,
                    'uid' => $uid,
                    'success' => false,
                ];

                switch ($type) {
                    case 'set_user':
                        $details['success'] = $client->setUser(
                            $uid,
                            (string) ($operation['user_id'] ?? (string) $uid),
                            (string) ($operation['name'] ?? 'EMPLOYE'),
                            (string) ($operation['password'] ?? ''),
                            (int) ($operation['role'] ?? 0),
                            (string) ($operation['card_no'] ?? '')
                        );
                        break;

                    case 'update_user':
                        $details['success'] = $client->updateUser($uid, $operation);
                        break;

                    case 'remove_user':
                        $details['success'] = $client->removeUser($uid);
                        break;

                    default:
                        $details['success'] = false;
                        $details['error'] = 'Type d’opération inconnu.';
                        break;
                }

                $result['operations'][] = $details;
                if ($details['success'] !== true) {
                    $result['success'] = false;
                    $result['error'] = sprintf('Erreur sur l’opération %s pour uid %d.', $type, $uid);
                }
            }
        } catch (Throwable $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
        } finally {
            try {
                $client->enableDevice();
            } catch (Throwable $e) {
                $result['success'] = false;
                $result['warning'] = $e->getMessage();
            }

            try {
                $client->disconnect();
            } catch (Throwable $e) {
                $result['warning_disconnect'] = $e->getMessage();
            }
        }

        return $result;
    }
}
