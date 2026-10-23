<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Analyse les horaires historiques et leur répartition par heure, mois et statut.
 */
class PointagesAnalyserHoraires extends BaseCommand
{
    protected $group = 'Pointages';
    protected $name = 'pointages:analyser-horaires';
    protected $description = 'Analyse les heures de pointage et leur proximité avec l’horaire par défaut.';
    protected $usage = 'pointages:analyser-horaires [--tenant=1]';
    protected $options = [
        '--tenant' => 'ID du tenant à analyser (défaut : 1).',
    ];

    public function run(array $params): int
    {
        $tenantOption = CLI::getOption('tenant');
        $tenantId = $tenantOption === null ? 1 : filter_var($tenantOption, FILTER_VALIDATE_INT);
        if ($tenantId === false || $tenantId < 1) {
            CLI::error('Le tenant doit être un identifiant entier positif.');
            return EXIT_ERROR;
        }

        try {
            $db = Database::connect();
            $tenant = $db->table('tenants')
                ->select('id')
                ->where('id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();
            if ($tenant === null) {
                CLI::error('Tenant introuvable.');
                return EXIT_ERROR;
            }

            $total = $db->table('pointages')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->countAllResults();
            $bornes = $db->table('pointages')
                ->select('MIN(date_pointage) AS premiere, MAX(date_pointage) AS derniere')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray() ?? [];
            $parStatut = $db->table('pointages')
                ->select('statut_calcul, COUNT(*) AS total')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->groupBy('statut_calcul')
                ->get()
                ->getResultArray();
            $parType = $db->table('pointages')
                ->select('type_evenement, COUNT(*) AS total')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->groupBy('type_evenement')
                ->get()
                ->getResultArray();
            $parHeure = $db->query(
                'SELECT HOUR(date_pointage) AS heure, COUNT(*) AS total
                 FROM pointages
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 GROUP BY HOUR(date_pointage)
                 ORDER BY total DESC, heure ASC',
                [$tenantId]
            )->getResultArray();
            $parMinute = $db->query(
                "SELECT DATE_FORMAT(date_pointage, '%H:%i') AS heure, COUNT(*) AS total
                 FROM pointages
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 GROUP BY DATE_FORMAT(date_pointage, '%H:%i')",
                [$tenantId]
            )->getResultArray();
            $parMois = $db->query(
                "SELECT DATE_FORMAT(date_pointage, '%Y-%m') AS mois, COUNT(*) AS total
                 FROM pointages
                 WHERE tenant_id = ? AND deleted_at IS NULL
                 GROUP BY DATE_FORMAT(date_pointage, '%Y-%m')
                 ORDER BY mois ASC",
                [$tenantId]
            )->getResultArray();
            $parNombreQuotidien = $db->query(
                'SELECT nombre_pointages, COUNT(*) AS journees, SUM(nombre_pointages) AS pointages
                 FROM (
                     SELECT DATE(p.date_pointage) AS jour,
                            COALESCE(CONCAT("e:", p.employe_id), CONCAT("b:", p.badge_id)) AS groupe_employe,
                            COUNT(*) AS nombre_pointages
                     FROM pointages p
                     WHERE p.tenant_id = ? AND p.deleted_at IS NULL
                     GROUP BY jour, groupe_employe
                 ) AS groupes_journaliers
                 GROUP BY nombre_pointages
                 ORDER BY nombre_pointages ASC',
                [$tenantId]
            )->getResultArray();
            $heuresPointageUnique = $db->query(
                'SELECT heure, COUNT(*) AS journees
                 FROM (
                     SELECT COALESCE(CONCAT("e:", p.employe_id), CONCAT("b:", p.badge_id)) AS groupe_employe,
                            DATE(p.date_pointage) AS jour,
                            HOUR(MIN(p.date_pointage)) AS heure,
                            COUNT(*) AS nombre_pointages
                     FROM pointages p
                     WHERE p.tenant_id = ? AND p.deleted_at IS NULL
                     GROUP BY groupe_employe, jour
                     HAVING nombre_pointages = 1
                 ) AS journees_uniques
                 GROUP BY heure
                 ORDER BY journees DESC, heure ASC',
                [$tenantId]
            )->getResultArray();
            $nonLies = $db->table('pointages')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->where('employe_id', null)
                ->countAllResults();

            $horaire = $db->table('tenant_horaires')
                ->select('id, nom, plages_horaires')
                ->where('tenant_id', $tenantId)
                ->where('est_defaut', 1)
                ->where('actif', 1)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get()
                ->getRowArray();
            if ($total > 0 && $horaire === null) {
                throw new \RuntimeException('Aucun horaire actif par défaut pour ce tenant.');
            }

            $parMinuteCompte = [];
            foreach ($parMinute as $ligne) {
                $parMinuteCompte[(string) $ligne['heure']] = (int) $ligne['total'];
            }
            $plages = json_decode((string) ($horaire['plages_horaires'] ?? '[]'), true);
            $plages = is_array($plages) ? $plages : [];

            CLI::write('=== Analyse des horaires de pointage ===', 'cyan');
            CLI::write('Tenant : ' . $tenantId);
            CLI::write('Total : ' . number_format($total, 0, ',', ' '));
            CLI::write('Période : ' . ((string) ($bornes['premiere'] ?? '—')) . ' → ' . ((string) ($bornes['derniere'] ?? '—')));
            CLI::write(sprintf(
                'Sans employe_id enregistré : %s (%.2f%%)',
                number_format($nonLies, 0, ',', ' '),
                $total > 0 ? $nonLies * 100 / $total : 0
            ));
            CLI::write('Horaire par défaut : ' . ($horaire === null ? 'aucun (aucun pointage)' : '#' . $horaire['id'] . ' ' . $horaire['nom']));
            CLI::write('Analyse agrégée en lecture seule; aucun pointage ni horaire n’est modifié.', 'yellow');

            CLI::write(PHP_EOL . 'Statuts calculés actuels :', 'cyan');
            foreach ($parStatut as $ligne) {
                CLI::write(sprintf(
                    '- %s : %s (%.2f%%)',
                    $ligne['statut_calcul'],
                    number_format((int) $ligne['total'], 0, ',', ' '),
                    $total > 0 ? (int) $ligne['total'] * 100 / $total : 0
                ));
            }

            CLI::write(PHP_EOL . 'Types d’événement actuels :', 'cyan');
            foreach ($parType as $ligne) {
                CLI::write(sprintf(
                    '- %s : %s (%.2f%%)',
                    $ligne['type_evenement'],
                    number_format((int) $ligne['total'], 0, ',', ' '),
                    $total > 0 ? (int) $ligne['total'] * 100 / $total : 0
                ));
            }

            CLI::write(PHP_EOL . 'Répartition par heure (top 20) :', 'cyan');
            foreach (array_slice($parHeure, 0, 20) as $ligne) {
                CLI::write(sprintf(
                    '- %02d:00 : %s (%.2f%%)',
                    (int) $ligne['heure'],
                    number_format((int) $ligne['total'], 0, ',', ' '),
                    $total > 0 ? (int) $ligne['total'] * 100 / $total : 0
                ));
            }

            CLI::write(PHP_EOL . 'Répartition par mois :', 'cyan');
            foreach ($parMois as $ligne) {
                CLI::write(sprintf(
                    '- %s : %s',
                    $ligne['mois'],
                    number_format((int) $ligne['total'], 0, ',', ' ')
                ));
            }

            CLI::write(PHP_EOL . 'Nombre de pointages par employé et par jour :', 'cyan');
            foreach ($parNombreQuotidien as $ligne) {
                CLI::write(sprintf(
                    '- %d pointage(s) : %s journées, %s pointages',
                    (int) $ligne['nombre_pointages'],
                    number_format((int) $ligne['journees'], 0, ',', ' '),
                    number_format((int) $ligne['pointages'], 0, ',', ' ')
                ));
            }
            CLI::write('Heures des journées avec un seul pointage (top 10) :', 'cyan');
            foreach (array_slice($heuresPointageUnique, 0, 10) as $ligne) {
                CLI::write(sprintf(
                    '- %02d:00 : %s journées',
                    (int) $ligne['heure'],
                    number_format((int) $ligne['journees'], 0, ',', ' ')
                ));
            }

            CLI::write(PHP_EOL . 'Pointages autour des heures de référence (horaire par défaut) :', 'cyan');
            foreach ($plages as $plage) {
                if (! is_array($plage) || ! isset($plage['type'], $plage['debut'], $plage['fin'])) {
                    continue;
                }
                $type = strtoupper((string) $plage['type']);
                $reference = in_array($type, ['CHECK_OUT', 'BREAK_IN'], true)
                    ? (string) $plage['fin']
                    : (string) $plage['debut'];
                $tolerance = filter_var($plage['tolerance_min'] ?? 10, FILTER_VALIDATE_INT);
                if ($tolerance === false || $tolerance < 0 || ! preg_match('/^\d{2}:\d{2}$/', $reference)) {
                    continue;
                }
                $minuteReference = ((int) substr($reference, 0, 2) * 60) + (int) substr($reference, 3, 2);
                $dansFenetre = 0;
                foreach ($parMinuteCompte as $heure => $nombre) {
                    $minute = ((int) substr($heure, 0, 2) * 60) + (int) substr($heure, 3, 2);
                    if (abs($minute - $minuteReference) <= $tolerance) {
                        $dansFenetre += $nombre;
                    }
                }
                CLI::write(sprintf(
                    '- %s autour de %s ± %d min : %s (%.2f%% du total)',
                    $type,
                    $reference,
                    $tolerance,
                    number_format($dansFenetre, 0, ',', ' '),
                    $total > 0 ? $dansFenetre * 100 / $total : 0
                ));
            }

            CLI::write(PHP_EOL . 'Les plages ci-dessus utilisent le seul horaire par défaut; les affectations historiques peuvent différer.', 'yellow');
            CLI::write('Aucune recommandation de changement de tolérance n’est appliquée par cette analyse.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error('Échec de l’analyse des horaires : ' . $exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
