<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Produit un diagnostic en lecture seule des pointages sans employé associé.
 */
class PointagesInvestiguerOrphelins extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:investiguer-orphelins';
    protected $description = 'Recherche les employés correspondant aux badges des pointages orphelins.';
    protected $usage = 'php spark pointages:investiguer-orphelins --tenant=1';
    protected $options = [
        '--tenant' => 'Identifiant du tenant à diagnostiquer (obligatoire).',
    ];

    /**
     * Affiche les pointages orphelins et les correspondances de badge trouvées.
     *
     * Cette commande ne modifie ni les pointages ni les employés.
     *
     * @param list<string> $params
     */
    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant') ?? CLI::getOption('--tenant');
        if ($tenantOption === null) {
            foreach (CLI::getOptions() as $optionName => $optionValue) {
                if (preg_match('/^tenant=(\d+)$/', $optionName, $matches)) {
                    $tenantOption = $matches[1];
                    break;
                }
            }
        }
        if ($tenantOption === null) {
            foreach ($params as $index => $param) {
                if (str_starts_with((string) $param, '--tenant=')) {
                    $tenantOption = substr((string) $param, strlen('--tenant='));
                    break;
                }
                if ($param === '--tenant' && isset($params[$index + 1])) {
                    $tenantOption = $params[$index + 1];
                    break;
                }
            }
        }
        $tenantId = filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('L’option --tenant est obligatoire et doit être un entier positif.');

            return EXIT_ERROR;
        }

        $db = Database::connect();
        $pointages = $db->table('pointages p')
            ->select('p.id, p.badge_id, p.date_pointage, p.capteur_id, p.deleted_at, c.nom AS capteur_nom')
            ->join('capteurs c', 'c.id = p.capteur_id AND c.tenant_id = p.tenant_id', 'left')
            ->where('p.tenant_id', $tenantId)
            ->where('p.employe_id', null)
            ->where('p.deleted_at', null)
            ->orderBy('p.date_pointage', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->get()
            ->getResultArray();

        CLI::write(sprintf('=== %d pointage(s) orphelin(s), tenant #%d ===', count($pointages), $tenantId));
        CLI::write('Diagnostic en lecture seule : aucune donnée ne sera modifiée.');
        CLI::write('');

        if ($pointages === []) {
            CLI::write('Aucun pointage orphelin actif.');

            return EXIT_SUCCESS;
        }

        foreach ($pointages as $index => $pointage) {
            $badgeId = (int) $pointage['badge_id'];
            $employes = $db->table('employes')
                ->select('id, matricule, nom, prenom, actif, deleted_at')
                ->where('tenant_id', $tenantId)
                ->where('badge_id', $badgeId)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            CLI::write(sprintf('%d. Pointage #%d', $index + 1, (int) $pointage['id']));
            CLI::write(sprintf('   Badge : %d', $badgeId));
            CLI::write(sprintf('   Date : %s', (string) $pointage['date_pointage']));
            CLI::write(sprintf('   Capteur : %s', $pointage['capteur_nom'] ?: ($pointage['capteur_id'] === null ? 'aucun' : 'capteur introuvable')));

            if ($employes === []) {
                CLI::write(sprintf('   → Aucun employé (actif, inactif ou supprimé) avec badge_id=%d.', $badgeId), 'yellow');
                CLI::write('   → Action : vérifier le badge à la source ; ne pas associer automatiquement.');
            } else {
                foreach ($employes as $employe) {
                    $etat = $employe['deleted_at'] !== null
                        ? 'supprimé'
                        : ((int) $employe['actif'] === 1 ? 'actif' : 'inactif');
                    CLI::write(sprintf(
                        '   → Employé #%d : %s %s (matricule %s), %s.',
                        (int) $employe['id'],
                        (string) $employe['nom'],
                        (string) $employe['prenom'],
                        (string) ($employe['matricule'] ?? 'N/A'),
                        $etat
                    ), $etat === 'actif' ? 'green' : 'yellow');
                }
                CLI::write(count($employes) > 1
                    ? '   → Plusieurs correspondances : arbitrage manuel requis.'
                    : '   → Correspondance à confirmer avant toute association.');
            }
            CLI::write('');
        }

        return EXIT_SUCCESS;
    }
}
