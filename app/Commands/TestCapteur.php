<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Jmrashed\Zkteco\Lib\ZKTeco;

/**
 * Commande de diagnostic pour tester un capteur ZKTeco réel.
 *
 * Exemple d’utilisation :
 * php spark capteur:test 10.100.28.230 --port=4370
 */
class TestCapteur extends BaseCommand
{
    /**
     * Groupe de commande CLI.
     */
    protected $group = 'Capteurs';

    /**
     * Nom de la commande.
     */
    protected $name = 'capteur:test';

    /**
     * Description courte de la commande.
     */
    protected $description = 'Teste la connexion et la lecture d’un capteur ZKTeco réel.';

    /**
     * Utilisation de la commande.
     */
    protected $usage = 'capteur:test [ip] [--port=4370]';

    /**
     * Arguments attendus.
     */
    protected $arguments = [
        'ip' => 'Adresse IP du capteur ZKTeco. Par défaut : 10.100.28.230',
    ];

    /**
     * Options reconnues.
     */
    protected $options = [
        '--port' => 'Port du capteur. Par défaut : 4370',
    ];

    /**
     * Exécute le test de diagnostic du capteur.
     */
    public function run(array $params): int
    {
        $firstParam = reset($params);
        $ip = $firstParam !== false ? $firstParam : (CLI::getOption('ip') ?? '10.100.28.230');
        $port = (int) (CLI::getOption('port') ?? $params['--port'] ?? 4370);

        if ($ip === null || trim((string) $ip) === '') {
            CLI::error('Adresse IP du capteur manquante. Exemple : php spark capteur:test 10.100.28.230 --port=4370');

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write('=== Diagnostic du capteur ZKTeco ===', 'green');
        CLI::write(sprintf('IP : %s', $ip), 'blue');
        CLI::write(sprintf('Port : %d', $port), 'blue');
        CLI::write('');

        $zk = null;

        try {
            $zk = new ZKTeco($ip, $port);

            CLI::write('Tentative de connexion...', 'yellow');
            $connected = $zk->connect();

            if (! $connected) {
                CLI::error(sprintf('Connexion impossible au capteur %s:%d.', $ip, $port));

                return EXIT_ERROR;
            }

            CLI::write('Connexion OK.', 'green');

            $device = [
                'serialNumber' => method_exists($zk, 'serialNumber') ? $zk->serialNumber() : null,
                'deviceName' => method_exists($zk, 'deviceName') ? $zk->deviceName() : null,
                'firmware' => method_exists($zk, 'fmVersion') ? $zk->fmVersion() : null,
                'platform' => method_exists($zk, 'platform') ? $zk->platform() : null,
                'version' => method_exists($zk, 'version') ? $zk->version() : null,
            ];

            CLI::write('Informations du dispositif :', 'green');
            foreach ($device as $key => $value) {
                CLI::write(sprintf('  - %s : %s', $key, $value ?? 'N/A'));
            }

            $users = $zk->getUser();
            $usersCount = is_array($users) ? count($users) : 0;
            CLI::write(sprintf('Utilisateurs lus : %d', $usersCount), $usersCount > 0 ? 'green' : 'yellow');

            if (is_array($users) && $usersCount > 0) {
                $sample = reset($users);
                $name = is_array($sample) && isset($sample['name']) ? $sample['name'] : 'N/A';
                $uid = is_array($sample) && isset($sample['uid']) ? $sample['uid'] : 'N/A';
                CLI::write(sprintf('Exemple : UID=%s | Nom=%s', $uid, $name));
            }

            $attendance = $zk->getAttendance();
            if (empty($attendance)) {
                CLI::write('Aucun pointage retourné par getAttendance(). Tentative avec getAttendanceWithLimit(1000)...', 'yellow');
                $attendance = $zk->getAttendanceWithLimit(1000);
            }

            $attendanceCount = is_array($attendance) ? count($attendance) : 0;
            CLI::write(sprintf('Pointages lus : %d', $attendanceCount), $attendanceCount > 0 ? 'green' : 'yellow');

            if (is_array($attendance) && $attendanceCount > 0) {
                $first = reset($attendance);
                CLI::write('Premier enregistrement :');
                CLI::write(print_r($first, true));
            }

            CLI::write('');
            CLI::write('Diagnostic terminé avec succès.', 'green');

            return EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error(sprintf('Erreur pendant le test du capteur : %s', $e->getMessage()));

            return EXIT_ERROR;
        } finally {
            if ($zk instanceof ZKTeco) {
                try {
                    $zk->disconnect();
                } catch (\Throwable $e) {
                    // Le disconnect ne doit pas casser le diagnostic.
                }
            }
        }
    }
}
