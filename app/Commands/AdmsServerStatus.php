<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Vérifie que le serveur ADMS écoute en TCP sur le port 8080.
 */
class AdmsServerStatus extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'adms:server-status';
    protected $description = 'Vérifie le listener TCP du serveur ADMS sur le port 8080.';
    protected $usage = 'adms:server-status';

    public function run(array $params): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            CLI::error('Cette commande utilise netstat/findstr et est prévue pour Windows.');

            return EXIT_ERROR;
        }

        if (! function_exists('shell_exec')) {
            CLI::error('shell_exec() est désactivé; impossible d’interroger netstat.');

            return EXIT_ERROR;
        }

        $output = @shell_exec('netstat -ano | findstr :8080');
        if (! is_string($output) || trim($output) === '') {
            CLI::error('Aucun listener détecté sur le port 8080.');
            CLI::write('Démarrez le serveur avec : php spark serve --host 0.0.0.0 --port 8080');

            return EXIT_ERROR;
        }

        $listeners = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^\s*TCP\s+(\S+:8080)\s+\S+\s+LISTENING\s+(\d+)\s*$/i', $line, $matches) === 1) {
                $listeners[] = [
                    'address' => $matches[1],
                    'pid' => $matches[2],
                ];
            }
        }

        if ($listeners === []) {
            CLI::error('Des connexions mentionnent le port 8080, mais aucun listener TCP LISTENING n’a été trouvé.');

            return EXIT_ERROR;
        }

        CLI::write('');
        CLI::write('=== État du listener ADMS ===', 'green');

        $listensOnAllInterfaces = false;
        foreach ($listeners as $listener) {
            CLI::write(sprintf(
                'ÉCOUTE : %s (PID %s)',
                $listener['address'],
                $listener['pid']
            ), 'green');

            if (in_array($listener['address'], ['0.0.0.0:8080', '[::]:8080'], true)) {
                $listensOnAllInterfaces = true;
            }
        }

        if ($listensOnAllInterfaces) {
            CLI::write('Le port est lié à toutes les interfaces réseau.', 'green');
        } else {
            CLI::write('Attention : le port semble lié à une adresse spécifique; vérifiez que le capteur peut la joindre.', 'yellow');
        }

        CLI::write('Ce contrôle confirme l’écoute locale; il ne vérifie pas le pare-feu ni la connectivité depuis le capteur.');

        return EXIT_SUCCESS;
    }
}