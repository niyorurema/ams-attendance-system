<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Jmrashed\Zkteco\Lib\Helper\Util;
use Jmrashed\Zkteco\Lib\ZKTeco;
use Throwable;

/**
 * Diagnostic en lecture seule de la récupération des pointages.
 *
 * Exemple : php spark capteur:diag-pointages 10.100.28.230
 */
class DiagnosticPointages extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'capteur:diag-pointages';
    protected $description = 'Diagnostique la réponse du capteur lors de la lecture des pointages.';
    protected $usage = 'capteur:diag-pointages [ip] [--port=4370]';
    protected $arguments = [
        'ip' => 'Adresse IP du capteur. Par défaut : 10.100.28.230',
    ];
    protected $options = [
        '--port' => 'Port UDP du capteur. Par défaut : 4370',
    ];

    /**
     * Exécute une lecture du journal et affiche les métadonnées de réponse.
     */
    public function run(array $params): int
    {
        $firstParam = reset($params);
        $ip = $firstParam !== false ? (string) $firstParam : '10.100.28.230';
        $port = (int) (CLI::getOption('port') ?? 4370);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            CLI::error(sprintf('Adresse IP invalide : %s', $ip));

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write('=== Diagnostic des pointages ZKTeco ===', 'green');
        CLI::write(sprintf('Cible : %s:%d/UDP', $ip, $port), 'blue');
        CLI::write('Lecture seule : aucune donnée ne sera effacée ou modifiée.');
        CLI::write('');

        $zk = null;

        try {
            $zk = new ZKTeco($ip, $port);

            CLI::write('Connexion au capteur...', 'yellow');
            if (! $zk->connect()) {
                CLI::error(sprintf('Connexion impossible au capteur %s:%d.', $ip, $port));

                return EXIT_ERROR;
            }
            CLI::write('Connexion OK.', 'green');

            CLI::write(sprintf('Plateforme : %s', $zk->platform() ?: 'N/A'));
            CLI::write(sprintf('Firmware : %s', $zk->version() ?: 'N/A'));
            CLI::write(sprintf('Serial : %s', $zk->serialNumber() ?: 'N/A'));
            CLI::write('');

            CLI::write('Lecture du journal avec getAttendance()...', 'yellow');
            $startedAt = microtime(true);
            $attendance = $zk->getAttendance();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $records = is_array($attendance) ? $attendance : [];
            CLI::write(sprintf('Type retourné : %s', get_debug_type($attendance)));
            CLI::write(sprintf('Nombre de pointages décodés : %d', count($records)), count($records) > 0 ? 'green' : 'yellow');
            CLI::write(sprintf('Durée de lecture : %d ms', $durationMs));

            if ($records !== []) {
                $firstRecord = reset($records);
                CLI::write('Premier pointage décodé :');
                CLI::write(print_r($firstRecord, true));
            }

            $response = $zk->_data_recv;
            $declaredBytes = Util::getSize($zk);

            CLI::write('État de la réponse UDP :', 'green');
            CLI::write(sprintf('  Section interne : %s', $zk->_section));
            CLI::write(sprintf('  Session : %d', $zk->_session_id));
            CLI::write(sprintf('  Taille de la dernière réponse : %d octets', strlen($response)));
            CLI::write(sprintf(
                '  Taille de transfert annoncée : %s',
                $declaredBytes === false ? 'indisponible (en-tête différent de CMD_PREPARE_DATA)' : $declaredBytes . ' octets'
            ));
            CLI::write(sprintf('  En-tête reçu (hex) : %s', bin2hex(substr($response, 0, 32)) ?: '(vide)'));

            if ($records === []) {
                CLI::write('');
                CLI::write('Interprétation :', 'yellow');
                if ($declaredBytes === false) {
                    CLI::write('Le capteur n’a pas fourni à la bibliothèque un en-tête de transfert reconnu. Vérifier la réponse à CMD_ATT_LOG_RRQ et la compatibilité du protocole/transport.');
                } elseif ($declaredBytes === 0) {
                    CLI::write('Le capteur annonce un transfert vide : la bibliothèque reçoit la commande, mais aucun journal de pointages exploitable n’est annoncé.');
                } else {
                    CLI::write('Le capteur annonce des données, mais getAttendance() ne les décode pas. Le format de trame ou la longueur des enregistrements peut différer de ce qu’attend le parseur de la bibliothèque.');
                }
                CLI::write('');
                CLI::write('Dans cette version, getAttendanceWithLimit() et getAttendanceByDateRange() réutilisent le même parseur et le même appel de lecture ; ils ne constituent pas des protocoles alternatifs.');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error(sprintf('Erreur pendant le diagnostic : %s', $e->getMessage()));

            return EXIT_ERROR;
        } finally {
            if ($zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (Throwable $e) {
                    // Une erreur de fermeture ne doit pas masquer les informations du diagnostic.
                }
            }
        }
    }
}
