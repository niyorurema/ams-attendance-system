<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use SplFileObject;
use Throwable;

/**
 * Affiche les dernières entrées ADMS du journal CI4 du jour.
 */
class AdmsWatch extends BaseCommand
{
    private const DEFAULT_LINES = 50;
    private const MAX_LINES = 5000;

    protected $group = 'Capteurs';
    protected $name = 'adms:watch';
    protected $description = 'Affiche les dernières lignes ADMS du journal du jour.';
    protected $usage = 'adms:watch [--lines=N] [--follow]';
    protected $options = [
        '--lines' => 'Nombre maximal de lignes ADMS à afficher (1-5000, défaut : 50).',
        '--follow' => 'Continue à afficher les nouvelles lignes ADMS jusqu’à Ctrl+C.',
    ];

    public function run(array $params): int
    {
        $requestedLines = CLI::getOption('lines');
        if ($requestedLines === null) {
            foreach (CLI::getOptions() as $option => $value) {
                if (preg_match('/^lines=(\d+)$/', $option, $matches) === 1) {
                    $requestedLines = $matches[1];
                    break;
                }
            }
        }

        $lineLimit = $requestedLines === null
            ? self::DEFAULT_LINES
            : filter_var($requestedLines, FILTER_VALIDATE_INT);

        if ($lineLimit === false || $lineLimit < 1 || $lineLimit > self::MAX_LINES) {
            CLI::error(sprintf('Valeur --lines invalide. Choisissez un nombre entre 1 et %d.', self::MAX_LINES));

            return EXIT_ERROR;
        }

        $date = date('Y-m-d');
        $logPath = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'log-' . $date . '.log';
        $follow = CLI::getOption('follow') === true;

        CLI::write('');
        CLI::write(sprintf('=== Dernières entrées ADMS du %s ===', $date), 'green');

        if (! is_file($logPath) || ! is_readable($logPath)) {
            CLI::write('Aucun fichier de log lisible pour aujourd’hui : ' . $logPath, 'yellow');
            if (! $follow) {
                CLI::write('Aucune requête ADMS n’a encore été journalisée aujourd’hui.');

                return EXIT_SUCCESS;
            }

            CLI::write('En attente du fichier de log; arrêt avec Ctrl+C.');

            return $this->followLog($logPath);
        }

        try {
            $file = new SplFileObject($logPath, 'r');
            $admsLines = [];

            while (! $file->eof()) {
                $line = $file->fgets();
                if (! is_string($line) || stripos($line, 'ADMS') === false) {
                    continue;
                }

                $admsLines[] = rtrim($line, "\r\n");
                if (count($admsLines) > $lineLimit) {
                    array_shift($admsLines);
                }
            }
        } catch (Throwable $e) {
            CLI::error('Impossible de lire le journal ADMS : ' . $e->getMessage());

            return EXIT_ERROR;
        }

        if ($admsLines === []) {
            CLI::write('Aucune ligne ADMS trouvée dans le log du jour.', 'yellow');
            CLI::write('Vérifiez que le capteur est configuré vers ce serveur et que ses requêtes atteignent l’application.');
        } else {
            CLI::write(sprintf('Affichage de %d ligne(s) depuis %s', count($admsLines), $logPath));
            foreach ($admsLines as $line) {
                CLI::write($line);
            }
        }

        if (! $follow) {
            return EXIT_SUCCESS;
        }

        CLI::write('');
        CLI::write('Suivi des nouvelles lignes ADMS actif; arrêt avec Ctrl+C.', 'green');

        return $this->followLog($logPath);
    }

    /**
     * Suit les ajouts au log, y compris après rotation quotidienne ou troncature.
     */
    private function followLog(string $initialPath): int
    {
        $path = $initialPath;
        $handle = null;
        $offset = 0;
        $buffer = '';

        while (true) {
            $currentPath = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'log-' . date('Y-m-d') . '.log';
            if ($currentPath !== $path) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                $path = $currentPath;
                $handle = null;
                $offset = 0;
                $buffer = '';
            }

            if (! is_file($path) || ! is_readable($path)) {
                usleep(500000);
                continue;
            }

            if (! is_resource($handle)) {
                $handle = @fopen($path, 'rb');
                if (! is_resource($handle)) {
                    usleep(500000);
                    continue;
                }

                if ($path === $initialPath) {
                    fseek($handle, 0, SEEK_END);
                    $offset = ftell($handle);
                }
            }

            clearstatcache(true, $path);
            $fileSize = filesize($path);
            if ($fileSize < $offset) {
                fseek($handle, 0);
                $offset = 0;
                $buffer = '';
            }

            if ($fileSize > $offset) {
                fseek($handle, $offset);
                $chunk = fread($handle, min(8192, $fileSize - $offset));
                $offset = ftell($handle);
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $newline), "\r");
                        $buffer = substr($buffer, $newline + 1);
                        if (stripos($line, 'ADMS') !== false) {
                            CLI::write($line);
                        }
                    }
                }
            }

            usleep(500000);
        }
    }
}