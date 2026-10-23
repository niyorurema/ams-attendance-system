<?php

declare(strict_types=1);

use Config\Database;
use Predis\Client;

if (! defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development');
}
define('FCPATH', __DIR__ . '/../public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../vendor/autoload.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

$tenantId = 1;
$flux = sprintf('ams:tenant:%d:pointages', $tenantId);
$redis = new Client([
    'scheme' => 'tcp',
    'host' => (string) env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'password' => env('REDIS_PASSWORD') ?: null,
    'database' => (int) env('REDIS_DATABASE', 0),
    'read_write_timeout' => 10,
    'timeout' => 2,
]);

echo "=== Diagnostic temps réel (lecture seule) ===\n\n";
echo "Ce script ne lance pas de scan et ne crée aucun pointage.\n";

try {
    $length = (int) $redis->executeRaw(['XLEN', $flux]);
    if ($length === 0) {
        printf("[Redis] Flux : %s\n", $flux);
        echo "[Redis] Longueur : 0 (flux absent ou vide)\n";
        echo "[Redis] Aucun événement à afficher.\n\n";
    } else {
        $info = $redis->executeRaw(['XINFO', 'STREAM', $flux]);
        $infos = [];
        for ($i = 0; $i + 1 < count($info); $i += 2) {
            $infos[(string) $info[$i]] = $info[$i + 1];
        }
        printf("[Redis] Flux : %s\n", $flux);
        printf("[Redis] Longueur : %s\n", (string) ($infos['length'] ?? 'inconnue'));
        printf("[Redis] Première entrée : %s\n", json_encode($infos['first-entry'] ?? null, JSON_UNESCAPED_SLASHES));
        printf("[Redis] Dernière entrée : %s\n\n", json_encode($infos['last-entry'] ?? null, JSON_UNESCAPED_SLASHES));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[Redis] Échec de lecture du flux : %s\n", $exception->getMessage()));
    exit(1);
}

try {
    $capteur = Database::connect()->table('capteurs')
        ->select('id, nom, adresse_ip, port, statut, dernier_pointage_sync')
        ->where('adresse_ip', '10.100.28.230')
        ->get()
        ->getRowArray();

    if ($capteur === null) {
        echo "[Capteur] 10.100.28.230 n’est pas enregistré dans la base.\n";
    } else {
        printf("[Capteur] #%d %s — %s:%d, statut=%s, dernier sync=%s\n",
            (int) $capteur['id'],
            (string) $capteur['nom'],
            (string) $capteur['adresse_ip'],
            (int) $capteur['port'],
            (string) $capteur['statut'],
            (string) ($capteur['dernier_pointage_sync'] ?? 'jamais')
        );
    }

    $recent = Database::connect()->table('pointages')
        ->select('id, date_pointage, type_evenement, source, badge_id')
        ->where('tenant_id', $tenantId)
        ->orderBy('id', 'DESC')
        ->limit(5)
        ->get()
        ->getResultArray();

    echo "\n[Base] Cinq derniers pointages du tenant #{$tenantId} :\n";
    foreach ($recent as $pointage) {
        printf("  #%d — %s — badge %s — %s — %s\n",
            (int) $pointage['id'],
            (string) $pointage['date_pointage'],
            (string) $pointage['badge_id'],
            (string) $pointage['type_evenement'],
            (string) $pointage['source']
        );
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[Base] Échec du diagnostic : %s\n", $exception->getMessage()));
    exit(1);
}

echo "\n[Essai réel] À effectuer séparément : ouvrir /pointages, démarrer le scan autorisé,\n";
echo "puis effectuer un pointage volontaire sur le capteur. Vérifier son apparition dans\n";
echo "l’interface et dans Redis. Cette étape écrit un événement de présence.\n";
