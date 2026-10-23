<?php

namespace App\Commands;

use App\Models\AuthLogModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Supprime les journaux d'authentification antérieurs à la période de conservation.
 */
class AuthPurgeLogs extends BaseCommand
{
    protected $group = 'Auth';
    protected $name = 'auth:purge-logs';
    protected $description = 'Purge les auth_logs de plus de N jours.';
    protected $usage = 'php spark auth:purge-logs [jours=90]';
    protected $arguments = [
        'jours' => 'Nombre de jours à conserver (défaut : 90).',
    ];

    public function run(array $params): int
    {
        $jours = $params[0] ?? 90;
        if (filter_var($jours, FILTER_VALIDATE_INT) === false || (int) $jours < 1) {
            CLI::error('Le nombre de jours doit être un entier supérieur ou égal à 1.');

            return EXIT_ERROR;
        }

        $jours = (int) $jours;
        $seuil = date('Y-m-d H:i:s', strtotime('-' . $jours . ' days'));

        CLI::write('=== Purge des logs d’authentification ===', 'cyan');
        CLI::write(sprintf('Seuil : %d jours (avant le %s)', $jours, $seuil));

        try {
            $model = new AuthLogModel();
            $total = $model->countAllResults();
            $aSupprimer = $model->where('created_at <', $seuil)->countAllResults();
            $aConserver = $total - $aSupprimer;

            CLI::write('');
            CLI::write('Statistiques :');
            CLI::write(sprintf('  Total auth_logs : %d', $total));
            CLI::write(sprintf('  À supprimer : %d', $aSupprimer));
            CLI::write(sprintf('  À conserver : %d', $aConserver));

            if ($aSupprimer === 0) {
                CLI::write('Aucun log à supprimer.', 'green');

                return EXIT_SUCCESS;
            }

            CLI::write('Suppression en cours...', 'yellow');
            if (! $model->where('created_at <', $seuil)->delete()) {
                throw new \RuntimeException('La suppression des journaux a échoué.');
            }

            CLI::write(sprintf('%d lignes supprimées.', $aSupprimer), 'green');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Erreur pendant la purge des journaux : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }
}
