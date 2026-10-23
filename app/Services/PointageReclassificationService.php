<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Recalcule les types et statuts des pointages d'un jour à partir des horaires applicables.
 */
final class PointageReclassificationService
{
    private BaseConnection $db;
    private PointageTypeService $typeService;

    public function __construct(?BaseConnection $db = null, ?PointageTypeService $typeService = null)
    {
        $this->db = $db ?? Database::connect();
        $this->typeService = $typeService ?? new PointageTypeService();
    }

    /**
     * @return array{traites:int,modifies:int,par_type:array<string,int>,par_statut:array<string,int>,par_horaire:array<int,int>}
     */
    public function reclassifierJournee(
        int $tenantId,
        string $jour,
        bool $apply,
        ?int $toleranceOverride = null
    ): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $jour);
        if ($tenantId < 1 || $date === false || $date->format('Y-m-d') !== $jour) {
            throw new \InvalidArgumentException('Le tenant ou la date de reclassification est invalide.');
        }

        $pointages = $this->db->table('pointages')
            ->select('id, employe_id, badge_id, date_pointage, type_evenement, statut_calcul')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->where('date_pointage >=', $jour . ' 00:00:00')
            ->where('date_pointage <=', $jour . ' 23:59:59')
            ->orderBy('date_pointage', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if ($pointages === []) {
            return ['traites' => 0, 'modifies' => 0, 'par_type' => [], 'par_statut' => [], 'par_horaire' => []];
        }

        $schedule = $this->chargerHoraires($tenantId, $jour, $pointages);
        $groupes = [];
        foreach ($pointages as $pointage) {
            $employeId = (int) ($pointage['employe_id'] ?? 0);
            if ($employeId < 1) {
                $employeId = $schedule['employe_par_badge'][(string) $pointage['badge_id']] ?? 0;
            }
            $key = $employeId > 0
                ? 'e:' . $employeId
                : 'b:' . (string) $pointage['badge_id'];
            $groupes[$key][] = $pointage;
        }

        $resultat = ['traites' => 0, 'modifies' => 0, 'par_type' => [], 'par_statut' => [], 'par_horaire' => []];
        if ($apply) {
            $this->db->transBegin();
        }

        try {
            foreach ($groupes as $key => $groupe) {
                $employeId = str_starts_with($key, 'e:') ? (int) substr($key, 2) : 0;
                $horaireId = $schedule['horaire_par_employe'][$employeId] ?? $schedule['horaire_defaut'];
                $plages = $schedule['plages_par_horaire'][$horaireId] ?? [];
                $classes = $this->typeService->determinerTypesEtStatuts($groupe, $plages, $toleranceOverride);

                foreach ($classes as $index => $classe) {
                    $resultat['traites']++;
                    $type = (string) $classe['type_evenement'];
                    $statut = (string) $classe['statut_calcul'];
                    $resultat['par_type'][$type] = ($resultat['par_type'][$type] ?? 0) + 1;
                    $resultat['par_statut'][$statut] = ($resultat['par_statut'][$statut] ?? 0) + 1;
                    $resultat['par_horaire'][$horaireId] = ($resultat['par_horaire'][$horaireId] ?? 0) + 1;

                    $original = $groupe[$index];
                    if ($type === (string) $original['type_evenement']
                        && $statut === (string) ($original['statut_calcul'] ?? '')
                    ) {
                        continue;
                    }

                    $resultat['modifies']++;
                    if ($apply) {
                        $this->db->table('pointages')
                            ->where('tenant_id', $tenantId)
                            ->where('id', (int) $original['id'])
                            ->update(['type_evenement' => $type, 'statut_calcul' => $statut]);
                        if ($this->db->affectedRows() !== 1) {
                            throw new \RuntimeException('Échec de mise à jour du pointage #' . $original['id'] . '.');
                        }
                    }
                }
            }

            if ($apply) {
                if ($this->db->transStatus() === false) {
                    throw new \RuntimeException('La transaction de reclassification a échoué.');
                }
                $this->db->transCommit();
            }
        } catch (\Throwable $e) {
            if ($apply) {
                $this->db->transRollback();
            }
            throw $e;
        }

        return $resultat;
    }

    /**
     * @param array<int, array<string, mixed>> $pointages
     * @return array{
     *   horaire_defaut:int,
     *   plages_par_horaire:array<int,array<int,array<string,mixed>>>,
     *   horaire_par_employe:array<int,int>,
     *   employe_par_badge:array<string,int>,
     *   noms_horaires:array<int,string>
     * }
     */
    private function chargerHoraires(int $tenantId, string $jour, array $pointages): array
    {
        $horaires = $this->db->table('tenant_horaires')
            ->select('id, nom, plages_horaires, est_defaut')
            ->where('tenant_id', $tenantId)
            ->where('actif', 1)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        $plagesParHoraire = [];
        $nomsHoraires = [];
        $horaireDefaut = 0;
        foreach ($horaires as $horaire) {
            $plages = json_decode((string) $horaire['plages_horaires'], true);
            $id = (int) $horaire['id'];
            $plagesParHoraire[$id] = is_array($plages) ? $plages : [];
            $nomsHoraires[$id] = (string) $horaire['nom'];
            if ((int) $horaire['est_defaut'] === 1) {
                $horaireDefaut = $id;
            }
        }
        if ($horaireDefaut === 0) {
            throw new \RuntimeException('Aucun horaire actif par défaut pour ce tenant.');
        }

        $employeIds = array_values(array_unique(array_filter(array_map(
            static fn (array $pointage): int => (int) ($pointage['employe_id'] ?? 0),
            $pointages
        ))));
        $badges = array_values(array_unique(array_column($pointages, 'badge_id')));
        $employeParBadge = [];
        if ($badges !== []) {
            $employes = $this->db->table('employes')
                ->select('id, badge_id')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->whereIn('badge_id', $badges)
                ->get()
                ->getResultArray();
            foreach ($employes as $employe) {
                $employeParBadge[(string) $employe['badge_id']] = (int) $employe['id'];
            }
        }

        $employeIds = array_values(array_unique(array_merge($employeIds, array_values($employeParBadge))));
        $horaireParEmploye = [];
        if ($employeIds !== []) {
            $affectations = $this->db->table('employe_horaire')
                ->select('employe_id, horaire_id')
                ->where('tenant_id', $tenantId)
                ->whereIn('employe_id', $employeIds)
                ->where('date_debut <=', $jour)
                ->groupStart()
                ->where('date_fin', null)
                ->orWhere('date_fin >=', $jour)
                ->groupEnd()
                ->orderBy('date_debut', 'DESC')
                ->get()
                ->getResultArray();
            foreach ($affectations as $affectation) {
                $employeId = (int) $affectation['employe_id'];
                $horaireId = (int) $affectation['horaire_id'];
                if (isset($plagesParHoraire[$horaireId]) && ! isset($horaireParEmploye[$employeId])) {
                    $horaireParEmploye[$employeId] = $horaireId;
                }
            }
        }

        return [
            'horaire_defaut' => $horaireDefaut,
            'plages_par_horaire' => $plagesParHoraire,
            'horaire_par_employe' => $horaireParEmploye,
            'employe_par_badge' => $employeParBadge,
            'noms_horaires' => $nomsHoraires,
        ];
    }
}
