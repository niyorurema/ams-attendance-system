<?php

namespace App\Commands;

use App\Database\Seeds\DemoAttendanceRules;
use App\Services\CalculPresenceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Recalcule et affiche une journée issue des pointages copiés dans le tenant DEMO.
 */
class DemoBoutEnBout extends BaseCommand
{
    private const DATE_PREFEREE = '2026-10-14';

    protected $group = 'Pointages';
    protected $name = 'demo:bout-en-bout';
    protected $description = 'Valide le calcul opt-in sur les pointages isolés du tenant DEMO.';
    protected $usage = 'php spark demo:bout-en-bout';

    public function run(array $params): int
    {
        $db = Database::connect();

        try {
            $tenant = $db->table('tenants')
                ->select('id, name, slug, actif_regles_nouvelles, notes')
                ->where('id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->get()
                ->getRowArray();
            if ($tenant === null
                || $tenant['slug'] !== DemoAttendanceRules::TENANT_DEMO_SLUG
                || $tenant['notes'] !== DemoAttendanceRules::MARQUEUR_TENANT
            ) {
                throw new RuntimeException('Tenant de démonstration #999 introuvable; exécutez d’abord DemoAttendanceRules.');
            }
            if ((int) $tenant['actif_regles_nouvelles'] !== 1) {
                throw new RuntimeException('Les nouvelles règles ne sont pas activées sur le tenant DEMO; relancez le seeder.');
            }

            $contrat = $db->table('employe_contrats_horaire c')
                ->select('c.id, c.employe_id, c.date_debut, c.date_fin, c.notes, e.nom, e.prenom, e.matricule')
                ->join('employes e', 'e.id = c.employe_id AND e.tenant_id = c.tenant_id')
                ->where('c.tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->like('c.notes', DemoAttendanceRules::MARQUEUR_CONTRAT, 'after')
                ->where('c.deleted_at', null)
                ->where('e.deleted_at', null)
                ->orderBy('c.date_fin', 'DESC')
                ->get()
                ->getRowArray();
            if ($contrat === null) {
                throw new RuntimeException('Contrat de démonstration introuvable; relancez le seeder.');
            }

            $employeId = (int) $contrat['employe_id'];
            $date = $this->choisirDate($db, $employeId);
            if ($date === null) {
                throw new RuntimeException('Aucun pointage copié n’est disponible pour recalculer la journée.');
            }
            if ($date < (string) $contrat['date_debut']
                || ($contrat['date_fin'] !== null && $date > (string) $contrat['date_fin'])
            ) {
                throw new RuntimeException(sprintf(
                    'La date %s des pointages est hors de la période du contrat (%s à %s).',
                    $date,
                    $contrat['date_debut'],
                    $contrat['date_fin'] ?? 'sans fin'
                ));
            }

            CLI::write(sprintf(
                'Tenant #%d — %s (%s %s), matricule %s',
                DemoAttendanceRules::TENANT_DEMO_ID,
                $tenant['name'],
                $contrat['prenom'],
                $contrat['nom'],
                $contrat['matricule']
            ));
            CLI::write(sprintf('Date recalculée : %s', $date));

            $projectionId = (new CalculPresenceService())->recalculerJournee($employeId, $date);
            if ($projectionId < 1) {
                throw new RuntimeException('Le calcul n’a créé aucune projection pour cette journée.');
            }

            $projection = $db->table('pointages_journee')
                ->where('id', $projectionId)
                ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->where('employe_id', $employeId)
                ->get()
                ->getRowArray();
            if ($projection === null) {
                throw new RuntimeException('La projection créée est introuvable.');
            }

            $pointages = $db->table('pointages')
                ->select('id, type_evenement, date_pointage, statut, statut_calcul')
                ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
                ->where('employe_id', $employeId)
                ->where('deleted_at', null)
                ->where('date_pointage >=', $date . ' 00:00:00')
                ->where('date_pointage <=', $date . ' 23:59:59')
                ->orderBy('date_pointage', 'ASC')
                ->get()
                ->getResultArray();

            $this->afficherResultat($projection, $pointages);
            CLI::write('Après vérification, nettoyer les données avec : php spark demo:nettoyer');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Échec de la démonstration : ' . $exception->getMessage());

            return EXIT_ERROR;
        }
    }

    protected function choisirDate(BaseConnection $db, int $employeId): ?string
    {
        $dates = $db->table('pointages')
            ->select('DATE(date_pointage) AS jour, COUNT(*) AS nombre', false)
            ->where('tenant_id', DemoAttendanceRules::TENANT_DEMO_ID)
            ->where('employe_id', $employeId)
            ->where('deleted_at', null)
            ->groupBy('DATE(date_pointage)', false)
            ->orderBy('nombre', 'DESC')
            ->orderBy('jour', 'DESC')
            ->get()
            ->getResultArray();

        foreach ($dates as $date) {
            if ($date['jour'] === self::DATE_PREFEREE && (int) $date['nombre'] >= 4) {
                return self::DATE_PREFEREE;
            }
        }

        return isset($dates[0]['jour']) ? (string) $dates[0]['jour'] : null;
    }

    /**
     * @param array<string, mixed> $projection
     * @param array<int, array<string, mixed>> $pointages
     */
    private function afficherResultat(array $projection, array $pointages): void
    {
        CLI::write(sprintf('Pointages bruts utilisés (%d) :', count($pointages)));
        foreach ($pointages as $pointage) {
            CLI::write(sprintf(
                '  #%d %s %s — %s (statut=%s, calcul=%s)',
                $pointage['id'],
                $pointage['type_evenement'],
                $pointage['date_pointage'],
                $pointage['statut'],
                $pointage['statut_calcul']
            ));
        }

        CLI::write('Résultat calculé :');
        foreach ([
            'ARRIVEE' => ['heure_arrivee', 'statut_arrivee'],
            'DEBUT_PAUSE' => ['heure_debut_pause', 'statut_debut_pause'],
            'FIN_PAUSE' => ['heure_fin_pause', 'statut_fin_pause'],
            'DEPART' => ['heure_depart', 'statut_depart'],
        ] as $type => [$heure, $statut]) {
            CLI::write(sprintf(
                '  %s : %s / %s',
                $type,
                $projection[$heure] ?? '—',
                $projection[$statut] ?? '—'
            ));
        }
        CLI::write('  Statut global : ' . $projection['statut_global']);
        CLI::write('  Heures travaillées : ' . $projection['heures_travaillees']);
    }
}
