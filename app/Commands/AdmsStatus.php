<?php

namespace App\Commands;

use App\Models\CapteurModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Vérifie l’accessibilité locale des endpoints ADMS et la configuration des capteurs.
 */
class AdmsStatus extends BaseCommand
{
    protected $group = 'Capteurs';
    protected $name = 'adms:status';
    protected $description = 'Vérifie les endpoints ADMS et les numéros de série configurés.';
    protected $usage = 'adms:status';

    public function run(array $params): int
    {
        $baseUrl = rtrim((string) config('App')->baseURL, '/') . '/';
        $parsedUrl = parse_url($baseUrl);

        CLI::write('');
        CLI::write('=== État du serveur ADMS ===', 'green');
        CLI::write('URL configurée : ' . $baseUrl);

        if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
            CLI::error('La valeur app.baseURL est invalide.');

            return EXIT_ERROR;
        }

        $configUrl = $baseUrl . 'iclock/cdata?SN=ADMS-STATUS&options=all';
        $requestUrl = $baseUrl . 'iclock/getrequest?SN=ADMS-STATUS';
        $configResult = $this->requestText($configUrl);
        $requestResult = $this->requestText($requestUrl);
        $endpointsReady = true;

        if ($configResult['status'] === 200 && str_contains($configResult['body'], 'GET OPTION FROM: ADMS-STATUS')) {
            CLI::write('GET /iclock/cdata : HTTP 200, configuration ADMS reçue', 'green');
        } else {
            CLI::error(sprintf(
                'GET /iclock/cdata : échec (HTTP %s). Vérifiez que le serveur écoute sur app.baseURL.',
                $configResult['status'] ?? 'inaccessible'
            ));
            $endpointsReady = false;
        }

        if ($requestResult['status'] === 200 && trim($requestResult['body']) === 'OK') {
            CLI::write('GET /iclock/getrequest : HTTP 200, réponse OK', 'green');
        } else {
            CLI::error(sprintf(
                'GET /iclock/getrequest : échec (HTTP %s).',
                $requestResult['status'] ?? 'inaccessible'
            ));
            $endpointsReady = false;
        }

        $localHosts = ['localhost', '127.0.0.1', '::1'];
        if (in_array(strtolower(trim($parsedUrl['host'], '[]')), $localHosts, true)) {
            CLI::write('Attention : app.baseURL utilise une adresse locale, inaccessible au capteur depuis le réseau.', 'yellow');
        }

        try {
            $sensors = (new CapteurModel())
                ->where('statut', 'ACTIF')
                ->findAll();

            $configuredSerials = 0;
            CLI::write('Capteurs actifs : ' . count($sensors));

            foreach ($sensors as $sensor) {
                $serialNumber = trim((string) ($sensor['numero_serie'] ?? ''));
                if ($serialNumber !== '') {
                    $configuredSerials++;
                    CLI::write(sprintf('  OK  %s : SN %s', $sensor['nom'], $serialNumber), 'green');
                } else {
                    CLI::write(sprintf('  !!  %s : numéro de série non renseigné', $sensor['nom']), 'yellow');
                }
            }

            if ($sensors === []) {
                CLI::write('Aucun capteur actif en base.', 'yellow');
            }

            if ($configuredSerials === 0) {
                CLI::error('Aucun capteur actif n’a de numéro de série. Les POST ADMS ne pourront pas être associés à un capteur.');
                $endpointsReady = false;
            }
        } catch (Throwable $e) {
            CLI::error('Impossible de vérifier les capteurs en base : ' . $e->getMessage());
            $endpointsReady = false;
        }

        CLI::write('');
        CLI::write('Ce contrôle vérifie les endpoints depuis cette machine, pas l’accès réseau du capteur ni le pare-feu Windows.');
        CLI::write('Pour l’accès LAN, lancer : php spark serve --host 0.0.0.0 --port 8080');

        if (! $endpointsReady) {
            CLI::error('État ADMS : INCOMPLET');

            return EXIT_ERROR;
        }

        CLI::write('État ADMS local : PRÊT', 'green');

        return EXIT_SUCCESS;
    }

    /**
     * Effectue une requête HTTP texte avec un délai court.
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
}