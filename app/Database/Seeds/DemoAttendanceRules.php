<?php

namespace App\Database\Seeds;

use App\Services\ContratsHoraireService;
use App\Services\ParametragePointageService;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Seeder;
use Config\Database;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Crée un tenant isolé et y copie un échantillon de pointages de démonstration.
 */
class DemoAttendanceRules extends Seeder
{
    private const TENANT_SOURCE_ID = 1;
    public const TENANT_DEMO_ID = 999;
    public const TENANT_DEMO_SLUG = 'demo';
    public const MARQUEUR_TENANT = 'Créé par DemoAttendanceRules; tenant isolé de démonstration.';
    public const MARQUEUR_EMPLOYE = 'DemoAttendanceRules:source_employe_id=';
    public const MARQUEUR_POINTAGE = 'DemoAttendanceRules:source_pointage_id=';
    public const MARQUEUR_CONTRAT = 'DemoAttendanceRules:contrat_employe_source_id=';
    private const NOMBRE_EMPLOYES = 3;
    private const NOMBRE_POINTAGES_PAR_EMPLOYE = 30;

    /** @var array<int, array<string, mixed>> */
    private const CRENEAUX = [
        [
            'type_creneau' => 'ARRIVEE',
            'mode' => 'LIMITE_TARDIVE',
            'heure_cible' => '07:30:00',
            'tolerance_minutes' => 10,
            'plage_debut' => null,
            'plage_fin' => null,
            'ordre_affichage' => 0,
        ],
        [
            'type_creneau' => 'DEBUT_PAUSE',
            'mode' => 'PLAGE',
            'heure_cible' => null,
            'tolerance_minutes' => 0,
            'plage_debut' => '12:00:00',
            'plage_fin' => '14:00:00',
            'ordre_affichage' => 1,
        ],
        [
            'type_creneau' => 'FIN_PAUSE',
            'mode' => 'LIMITE_TARDIVE',
            'heure_cible' => '14:00:00',
            'tolerance_minutes' => 10,
            'plage_debut' => null,
            'plage_fin' => null,
            'ordre_affichage' => 2,
        ],
        [
            'type_creneau' => 'DEPART',
            'mode' => 'LIMITE_PRECOCE',
            'heure_cible' => '17:30:00',
            'tolerance_minutes' => 10,
            'plage_debut' => null,
            'plage_fin' => null,
            'ordre_affichage' => 3,
        ],
    ];

    public function run(): void
    {
        $db = Database::connect();
        $tenantSource = $this->chargerTenantSource($db);
        $tenantDemo = $this->verifierTenantDemo($db);
        $employesSource = $this->chargerEmployesSource($db);
        if (count($employesSource) !== self::NOMBRE_EMPLOYES) {
            throw new RuntimeException('Impossible de trouver trois employés actifs avec des pointages dans le tenant source.');
        }

        $pointagesParEmploye = [];
        foreach ($employesSource as $employeSource) {
            $pointagesParEmploye[(int) $employeSource['id']] = $this->chargerPointagesSource(
                $db,
                (int) $employeSource['id']
            );
            if (count($pointagesParEmploye[(int) $employeSource['id']]) !== self::NOMBRE_POINTAGES_PAR_EMPLOYE) {
                throw new RuntimeException(sprintf(
                    'L’employé source #%d ne dispose pas de %d pointages à copier.',
                    $employeSource['id'],
                    self::NOMBRE_POINTAGES_PAR_EMPLOYE
                ));
            }
        }

        $db->transBegin();
        try {
            $tenantDemoId = $this->creerOuVerifierTenantDemo($db, $tenantSource, $tenantDemo);
            $employesDemo = [];
            foreach ($employesSource as $employeSource) {
                $employesDemo[(int) $employeSource['id']] = $this->dupliquerEmploye($db, $tenantDemoId, $employeSource);
            }

            foreach ($employesSource as $employeSource) {
                $sourceId = (int) $employeSource['id'];
                $this->dupliquerPointages(
                    $db,
                    $tenantDemoId,
                    (int) $employesDemo[$sourceId]['id'],
                    $pointagesParEmploye[$sourceId]
                );
            }

            $this->synchroniserCreneaux($db, $tenantDemoId);
            $this->initialiserParametres($db, $tenantDemoId);
            $this->synchroniserContrat($db, $tenantDemoId, $employesSource[0], $employesDemo[(int) $employesSource[0]['id']], $pointagesParEmploye[(int) $employesSource[0]['id']]);
            $this->activerOptIn($db, $tenantDemoId);

            if ($db->transStatus() === false) {
                throw new RuntimeException('Échec de l’écriture des données de démonstration.');
            }
            $db->transCommit();
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        CLI::write(sprintf(
            'Tenant de démonstration #%d préparé : %d employés et %d pointages. L’opt-in est activé.',
            self::TENANT_DEMO_ID,
            count($employesDemo),
            array_sum(array_map('count', $pointagesParEmploye))
        ));
        foreach ($employesSource as $employeSource) {
            CLI::write(sprintf(
                '  #%d %s %s : %d pointages (dernier le %s)',
                $employeSource['id'],
                $employeSource['nom'],
                $employeSource['prenom'],
                count($pointagesParEmploye[(int) $employeSource['id']]),
                $pointagesParEmploye[(int) $employeSource['id']][0]['date_pointage']
            ));
        }
        CLI::write('Le tenant source #1 et ses données n’ont pas été modifiés.');
    }

    /** @return array<string, mixed> */
    private function chargerTenantSource(BaseConnection $db): array
    {
        $tenant = $db->table('tenants')
            ->where('id', self::TENANT_SOURCE_ID)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $tenant ?? throw new RuntimeException('Tenant source #1 introuvable.');
    }

    /** @return array<string, mixed>|null */
    private function verifierTenantDemo(BaseConnection $db): ?array
    {
        $tenantId = $db->table('tenants')->where('id', self::TENANT_DEMO_ID)->get()->getRowArray();
        $tenantSlug = $db->table('tenants')->where('slug', self::TENANT_DEMO_SLUG)->get()->getRowArray();

        if ($tenantId !== null && (
            $tenantId['slug'] !== self::TENANT_DEMO_SLUG
            || $tenantId['notes'] !== self::MARQUEUR_TENANT
            || $tenantId['deleted_at'] !== null
        )) {
            throw new RuntimeException('L’identifiant tenant 999 est déjà utilisé ou supprimé; il ne sera pas réutilisé par le seeder.');
        }
        if ($tenantSlug !== null && (int) $tenantSlug['id'] !== self::TENANT_DEMO_ID) {
            throw new RuntimeException('Le slug demo est déjà utilisé par un autre tenant.');
        }

        return $tenantId;
    }

    /** @return array<int, array<string, mixed>> */
    private function chargerEmployesSource(BaseConnection $db): array
    {
        return $db->query(
            'SELECT e.*
             FROM employes e
             INNER JOIN pointages p ON p.employe_id = e.id AND p.tenant_id = e.tenant_id
             WHERE e.tenant_id = ? AND e.actif = 1 AND e.deleted_at IS NULL AND p.deleted_at IS NULL
             GROUP BY e.id
             ORDER BY COUNT(p.id) DESC, e.id ASC
             LIMIT ' . self::NOMBRE_EMPLOYES,
            [self::TENANT_SOURCE_ID]
        )->getResultArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function chargerPointagesSource(BaseConnection $db, int $employeSourceId): array
    {
        return $db->table('pointages')
            ->where('tenant_id', self::TENANT_SOURCE_ID)
            ->where('employe_id', $employeSourceId)
            ->where('deleted_at', null)
            ->orderBy('date_pointage', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(self::NOMBRE_POINTAGES_PAR_EMPLOYE)
            ->get()
            ->getResultArray();
    }

    /** @param array<string, mixed> $tenantSource
     *  @param array<string, mixed>|null $tenantDemo
     */
    private function creerOuVerifierTenantDemo(BaseConnection $db, array $tenantSource, ?array $tenantDemo): int
    {
        $horodatage = date('Y-m-d H:i:s');
        if ($tenantDemo === null) {
            $db->table('tenants')->insert([
                'id' => self::TENANT_DEMO_ID,
                'name' => 'DÉMO - Tenant de test',
                'slug' => self::TENANT_DEMO_SLUG,
                'timezone' => $tenantSource['timezone'],
                'jours_travailles' => $tenantSource['jours_travailles'],
                'is_active' => 1,
                'actif_regles_nouvelles' => 0,
                'notes' => self::MARQUEUR_TENANT,
                'created_at' => $horodatage,
                'updated_at' => $horodatage,
            ]);
        } else {
            $db->table('tenants')
                ->where('id', self::TENANT_DEMO_ID)
                ->update([
                    'name' => 'DÉMO - Tenant de test',
                    'timezone' => $tenantSource['timezone'],
                    'jours_travailles' => $tenantSource['jours_travailles'],
                    'is_active' => 1,
                    'updated_at' => $horodatage,
                ]);
        }

        return self::TENANT_DEMO_ID;
    }

    /** @param array<string, mixed> $employeSource
     *  @return array<string, mixed>
     */
    private function dupliquerEmploye(BaseConnection $db, int $tenantDemoId, array $employeSource): array
    {
        $sourceId = (int) $employeSource['id'];
        $marqueur = self::MARQUEUR_EMPLOYE . $sourceId;
        $existant = $db->table('employes')
            ->where('tenant_id', $tenantDemoId)
            ->where('notes', $marqueur)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        $horodatage = date('Y-m-d H:i:s');
        $donnees = [
            'tenant_id' => $tenantDemoId,
            'matricule' => 'DEMO_' . $sourceId . '_' . $employeSource['matricule'],
            'badge_id' => $employeSource['badge_id'],
            'nom' => $employeSource['nom'],
            'prenom' => $employeSource['prenom'],
            'actif' => 1,
            'notes' => $marqueur,
            'created_at' => $horodatage,
            'updated_at' => $horodatage,
        ];
        $donnees['matricule'] = mb_substr((string) $donnees['matricule'], 0, 50);

        if ($existant === null) {
            $db->table('employes')->insert($donnees);
            $id = (int) $db->insertID();
        } else {
            $db->table('employes')->where('id', (int) $existant['id'])->update($donnees);
            $id = (int) $existant['id'];
        }

        $employe = $db->table('employes')
            ->where('id', $id)
            ->where('tenant_id', $tenantDemoId)
            ->get()
            ->getRowArray();

        return $employe ?? throw new RuntimeException(sprintf('Impossible de créer l’employé de démonstration source #%d.', $sourceId));
    }

    /**
     * @param array<int, array<string, mixed>> $pointagesSource
     */
    private function dupliquerPointages(
        BaseConnection $db,
        int $tenantDemoId,
        int $employeDemoId,
        array $pointagesSource
    ): void {
        $idsSource = [];
        foreach ($pointagesSource as $pointage) {
            $sourceId = (int) $pointage['id'];
            $idsSource[] = $sourceId;
            $marqueur = self::MARQUEUR_POINTAGE . $sourceId;
            $existant = $db->table('pointages')
                ->where('tenant_id', $tenantDemoId)
                ->where('notes', $marqueur)
                ->get()
                ->getRowArray();
            $donnees = [
                'tenant_id' => $tenantDemoId,
                'employe_id' => $employeDemoId,
                'badge_id' => $pointage['badge_id'],
                'capteur_id' => $pointage['capteur_id'],
                'shift_id' => null,
                'type_evenement' => $pointage['type_evenement'],
                'statut_calcul' => $pointage['statut_calcul'],
                'date_pointage' => $pointage['date_pointage'],
                'date_heure_locale' => $pointage['date_heure_locale'],
                'source' => $pointage['source'],
                'statut' => $pointage['statut'],
                'raw_data' => null,
                'notes' => $marqueur,
                'deleted_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($existant === null) {
                $donnees['created_at'] = date('Y-m-d H:i:s');
                $db->table('pointages')->insert($donnees);
            } else {
                $db->table('pointages')->where('id', (int) $existant['id'])->update($donnees);
            }
        }

        $query = $db->table('pointages')
            ->where('tenant_id', $tenantDemoId)
            ->where('employe_id', $employeDemoId)
            ->like('notes', self::MARQUEUR_POINTAGE, 'after');
        if ($idsSource !== []) {
            $marqueurs = array_map(static fn (int $id): string => self::MARQUEUR_POINTAGE . $id, $idsSource);
            $query->whereNotIn('notes', $marqueurs);
        }
        $query->delete();
    }

    private function synchroniserCreneaux(BaseConnection $db, int $tenantDemoId): void
    {
        $horodatage = date('Y-m-d H:i:s');
        foreach (self::CRENEAUX as $creneau) {
            $existant = $db->table('tenant_creneaux_pointage')
                ->where('tenant_id', $tenantDemoId)
                ->where('type_creneau', $creneau['type_creneau'])
                ->where('deleted_at', null)
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();
            $donnees = $creneau + [
                'tenant_id' => $tenantDemoId,
                'actif' => 1,
                'notes' => 'Configuration de démonstration AMS.',
                'deleted_at' => null,
                'updated_at' => $horodatage,
            ];

            if ($existant === null) {
                $donnees['created_at'] = $horodatage;
                $db->table('tenant_creneaux_pointage')->insert($donnees);
            } else {
                $db->table('tenant_creneaux_pointage')->where('id', (int) $existant['id'])->update($donnees);
            }
        }
    }

    private function initialiserParametres(BaseConnection $db, int $tenantDemoId): void
    {
        (new ParametragePointageService($db))->initialiserParametresParDefaut($tenantDemoId);
    }

    /**
     * @param array<string, mixed> $employeSource
     * @param array<string, mixed> $employeDemo
     * @param array<int, array<string, mixed>> $pointagesSource
     */
    private function synchroniserContrat(
        BaseConnection $db,
        int $tenantDemoId,
        array $employeSource,
        array $employeDemo,
        array $pointagesSource
    ): void {
        $derniereDate = substr((string) $pointagesSource[0]['date_pointage'], 0, 10);
        $dateDebut = (new DateTimeImmutable($derniereDate))->modify('-30 days')->format('Y-m-d');
        $dateFin = (new DateTimeImmutable($derniereDate))->modify('+1 day')->format('Y-m-d');
        $marqueur = self::MARQUEUR_CONTRAT . (int) $employeSource['id'];
        $donnees = [
            'mode_travail' => 'TEMPS_PLEIN',
            'heure_debut' => '07:30',
            'heure_fin' => '17:30',
            'jours_travail' => [1, 2, 3, 4, 5],
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'actif' => 1,
            'notes' => $marqueur,
        ];
        $existant = $db->table('employe_contrats_horaire')
            ->where('tenant_id', $tenantDemoId)
            ->where('employe_id', (int) $employeDemo['id'])
            ->where('notes', $marqueur)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();
        $service = new ContratsHoraireService($db);

        if ($existant === null) {
            $service->creer($tenantDemoId, (int) $employeDemo['id'], $donnees);
        } else {
            $service->modifier((int) $existant['id'], $donnees);
        }
    }

    private function activerOptIn(BaseConnection $db, int $tenantDemoId): void
    {
        $db->table('tenants')
            ->where('id', $tenantDemoId)
            ->where('slug', self::TENANT_DEMO_SLUG)
            ->where('notes', self::MARQUEUR_TENANT)
            ->update([
                'actif_regles_nouvelles' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
