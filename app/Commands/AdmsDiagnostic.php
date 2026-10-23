<?php

namespace App\Commands;

use App\Models\CapteurModel;
use App\Models\EmployeModel;
use App\Models\PointageModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use SplFileObject;
use Throwable;

/**
 * Diagnostique la configuration ADMS d’un capteur côté serveur et base.
 */
class AdmsDiagnostic extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'adms:diagnostic';
    protected $description = 'Diagnostique la réception ADMS d’un capteur côté serveur et base.';
    protected $usage = 'adms:diagnostic {capteur_id}';
    protected $arguments = [
        'capteur_id' => 'Identifiant du capteur à diagnostiquer.',
    ];

    public function run(array $params): int
    {
        $rawCapteurId = $params[0] ?? null;
        if ($rawCapteurId === null
            || filter_var($rawCapteurId, FILTER_VALIDATE_INT) === false
            || (int) $rawCapteurId < 1
        ) {
            CLI::error('Identifiant invalide. Usage : php spark adms:diagnostic {capteur_id}');

            return EXIT_ERROR;
        }

        $capteurId = (int) $rawCapteurId;
        $hasIssues = false;

        CLI::write('');
        CLI::write(sprintf('=== Diagnostic ADMS complet : capteur #%d ===', $capteurId), 'green');

        try {
            $capteur = (new CapteurModel())->find($capteurId);
            if (! is_array($capteur)) {
                CLI::error(sprintf('Capteur #%d introuvable ou supprimé.', $capteurId));

                return EXIT_ERROR;
            }

            $tenantId = (int) $capteur['tenant_id'];
            $serialNumber = trim((string) ($capteur['numero_serie'] ?? ''));

            CLI::write('');
            CLI::write('1. Capteur et base', 'blue');
            CLI::write(sprintf('  Nom : %s', $capteur['nom']));
            CLI::write(sprintf('  Tenant : #%d', $tenantId));
            CLI::write(sprintf('  Adresse de gestion : %s:%s', $capteur['adresse_ip'], $capteur['port']));
            CLI::write(sprintf('  Numéro de série : %s', $serialNumber !== '' ? $serialNumber : 'MANQUANT'));
            CLI::write(sprintf('  Statut : %s', $capteur['statut']));

            if ($serialNumber === '') {
                CLI::error('  Anomalie : le numéro de série est vide; le push ne peut pas être rattaché à ce capteur.');
                $hasIssues = true;
            }
            if ($capteur['statut'] !== 'ACTIF') {
                CLI::error('  Anomalie : le capteur n’est pas actif.');
                $hasIssues = true;
            }

            $employeeCount = (new EmployeModel())
                ->where('tenant_id', $tenantId)
                ->countAllResults();
            $attendanceModel = new PointageModel();
            $attendanceCount = $attendanceModel
                ->where('tenant_id', $tenantId)
                ->where('capteur_id', $capteurId)
                ->countAllResults();
            $latestAttendance = (new PointageModel())
                ->select('date_pointage')
                ->where('tenant_id', $tenantId)
                ->where('capteur_id', $capteurId)
                ->orderBy('date_pointage', 'DESC')
                ->first();

            CLI::write(sprintf('  Employés du tenant : %d', $employeeCount));
            CLI::write(sprintf('  Pointages de ce capteur en base : %d', $attendanceCount));
            CLI::write(sprintf('  Dernier pointage : %s', $latestAttendance['date_pointage'] ?? 'aucun'));

            if ($employeeCount === 0) {
                CLI::error('  Anomalie : aucun employé trouvé pour le tenant du capteur.');
                $hasIssues = true;
            }

            CLI::write('');
            CLI::write('2. Listener HTTP', 'blue');
            $listeners = $this->getPortListeners(8080);

            if ($listeners === null) {
                CLI::write('  Vérification indisponible : netstat/shell_exec indisponible sur cet environnement.', 'yellow');
                $hasIssues = true;
            } elseif ($listeners === []) {
                CLI::error('  Aucun listener TCP trouvé sur le port 8080.');
                CLI::write('  Démarrer avec : php spark serve --host 0.0.0.0 --port 8080');
                $hasIssues = true;
            } else {
                foreach ($listeners as $listener) {
                    CLI::write(sprintf('  Écoute : %s (PID %s)', $listener['address'], $listener['pid']), 'green');
                }

                $allInterfaces = array_filter($listeners, static fn (array $listener): bool => in_array(
                    $listener['address'],
                    ['0.0.0.0:8080', '[::]:8080'],
                    true
                )) !== [];

                if (! $allInterfaces) {
                    CLI::write('  Avertissement : aucune écoute sur toutes les interfaces.', 'yellow');
                    $hasIssues = true;
                }
            }

            CLI::write('');
            CLI::write('3. Endpoints HTTP depuis le serveur', 'blue');
            $baseUrl = rtrim((string) config('App')->baseURL, '/') . '/';
            CLI::write('  app.baseURL : ' . $baseUrl);
            $parsedUrl = parse_url($baseUrl);

            if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
                CLI::error('  app.baseURL est invalide.');
                $hasIssues = true;
            } else {
                $host = strtolower(trim($parsedUrl['host'], '[]'));
                if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                    CLI::write('  Avertissement : cette URL loopback n’est pas joignable depuis le capteur.', 'yellow');
                    $hasIssues = true;
                }

                $probeSerial = 'ADMS-DIAGNOSTIC-PROBE';
                $configResult = $this->requestText($baseUrl . 'iclock/cdata?SN=' . rawurlencode($probeSerial) . '&options=all');
                $requestResult = $this->requestText($baseUrl . 'iclock/getrequest?SN=' . rawurlencode($probeSerial));

                if ($configResult['status'] === 200 && str_contains($configResult['body'], 'GET OPTION FROM: ' . $probeSerial)) {
                    CLI::write('  GET /iclock/cdata : HTTP 200, configuration reçue.', 'green');
                } else {
                    CLI::error(sprintf('  GET /iclock/cdata : échec HTTP %s.', $configResult['status'] ?? 'inaccessible'));
                    $hasIssues = true;
                }

                if ($requestResult['status'] === 200 && trim($requestResult['body']) === 'OK') {
                    CLI::write('  GET /iclock/getrequest : HTTP 200, réponse OK.', 'green');
                } else {
                    CLI::error(sprintf('  GET /iclock/getrequest : échec HTTP %s.', $requestResult['status'] ?? 'inaccessible'));
                    $hasIssues = true;
                }
            }

            CLI::write('');
            CLI::write('4. Logs ADMS du jour', 'blue');
            $logReport = $this->findTodayAdmsEntries($serialNumber, (string) $capteur['adresse_ip']);
            CLI::write('  Fichier : ' . $logReport['path']);
            CLI::write(sprintf('  Entrées depuis l’IP du capteur (%s) : %d', $capteur['adresse_ip'], $logReport['source_count']));
            CLI::write(sprintf('  Entrées avec le SN attendu depuis cette IP : %d', $logReport['sensor_count']));
            CLI::write(sprintf('  Entrées portant ce SN mais non attribuables au capteur : %d', $logReport['unverified_count']));

            foreach ($logReport['lines'] as $line) {
                CLI::write('  ' . $line);
            }

            if ($logReport['source_count'] === 0) {
                CLI::write('  Aucune requête venant de l’adresse IP du capteur n’a été journalisée aujourd’hui.', 'yellow');
                $hasIssues = true;
            } elseif ($serialNumber !== '' && $logReport['sensor_count'] === 0) {
                CLI::write('  L’IP a contacté le serveur, mais aucun de ces événements ne porte le SN configuré.', 'yellow');
                $hasIssues = true;
            }

            CLI::write('');
            CLI::write('Ce diagnostic vérifie le serveur local et la base; il ne teste pas le routage, le pare-feu ni une requête émise depuis le capteur.');

            if ($hasIssues) {
                CLI::error('Diagnostic terminé : anomalie(s) ou communication capteur non observée(s).');

                return EXIT_ERROR;
            }

            CLI::write('Diagnostic terminé : requêtes observées depuis l’IP du capteur avec son SN.', 'green');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Diagnostic interrompu : ' . $e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * Renvoie les listeners TCP sur le port demandé, ou null si indisponible.
     *
     * @return list<array{address:string,pid:string}>|null
     */
    private function getPortListeners(int $port): ?array
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! function_exists('shell_exec')) {
            return null;
        }

        $output = @shell_exec(sprintf('netstat -ano | findstr :%d', $port));
        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        $listeners = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match(sprintf('/^\s*TCP\s+(\S+:%d)\s+\S+\s+LISTENING\s+(\d+)\s*$/i', $port), $line, $matches) === 1) {
                $listeners[] = [
                    'address' => $matches[1],
                    'pid' => $matches[2],
                ];
            }
        }

        return $listeners;
    }

    /**
     * Effectue une requête HTTP texte avec un timeout court.
     *
     * @return array{status:?int,body:string}
     */
    private function requestText(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'ignore_errors' => true,
                'header' => "Accept: text/plain\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = null;

        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => $body === false ? '' : $body,
        ];
    }

    /**
     * Lit les dernières entrées du log du jour associées au SN.
     *
     * @return array{path:string,count:int,lines:list<string>}
     */
    private function findTodayAdmsEntries(string $serialNumber, string $sensorIp): array
    {
        $path = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'log-' . date('Y-m-d') . '.log';
        if ($serialNumber === '' || $sensorIp === '' || ! is_file($path) || ! is_readable($path)) {
            return [
                'path' => $path,
                'source_count' => 0,
                'sensor_count' => 0,
                'unverified_count' => 0,
                'lines' => [],
            ];
        }

        $file = new SplFileObject($path, 'r');
        $sourceCount = 0;
        $sensorCount = 0;
        $unverifiedCount = 0;
        $recentLines = [];
        $serialNeedle = 'SN=' . $serialNumber;
        $ipNeedle = 'source_ip=' . $sensorIp;

        while (! $file->eof()) {
            $line = $file->fgets();
            if (! is_string($line) || stripos($line, 'ADMS') === false) {
                continue;
            }

            $hasSerial = str_contains($line, $serialNeedle);
            $fromSensorIp = str_contains($line, $ipNeedle);

            if ($fromSensorIp) {
                $sourceCount++;
            }

            if ($hasSerial && $fromSensorIp) {
                $sensorCount++;
            } elseif ($hasSerial) {
                $unverifiedCount++;
            }

            if ($fromSensorIp || $hasSerial) {
                $recentLines[] = rtrim($line, "\r\n");
            }

            if (count($recentLines) > 10) {
                array_shift($recentLines);
            }
        }

        return [
            'path' => $path,
            'source_count' => $sourceCount,
            'sensor_count' => $sensorCount,
            'unverified_count' => $unverifiedCount,
            'lines' => $recentLines,
        ];
    }
}