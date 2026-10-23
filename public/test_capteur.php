<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/../vendor/autoload.php';

use Jmrashed\Zkteco\Lib\ZKTeco;

$ip = '10.100.28.230';
$port = 4370;

echo "=== TEST CAPTEUR COMPLET ===\n";
echo "Cible : $ip:$port\n\n";

try {
    $zk = new ZKTeco($ip, $port);
    
    // 1. Connexion
    echo "[1] Connexion... ";
    $connected = $zk->connect();
    echo $connected ? "✅ OK\n" : "❌ ÉCHEC\n";
    if (!$connected) exit();
    
    // 2. Libérer le capteur
    $zk->disableDevice();
    echo "[2] Device désactivé ✅\n\n";
    
    // 3. Heure
    echo "Heure capteur : " . $zk->getTime() . "\n\n";
    
    // 4. Utilisateurs (juste les 5 premiers, encodage corrigé)
    echo "=== UTILISATEURS (5 premiers, encodage corrigé) ===\n";
    $start = microtime(true);
    $users = $zk->getUser();
    $elapsed = round(microtime(true) - $start, 2);
    echo "⏱️ Durée : {$elapsed}s | Total : " . count($users) . "\n\n";
    
    $i = 0;
    foreach ($users as $user) {
        if ($i++ >= 5) break;
        // Correction encodage
        $name = mb_convert_encoding($user['name'], 'UTF-8', 'ISO-8859-1');
        echo "  - uid={$user['uid']}, userid={$user['userid']}, name='$name'\n";
    }
    echo "\n";
    
    // 5. TEST CRUCIAL : getAttendance() BRUT
    echo "=== POINTAGES BRUT (getAttendance) ===\n";
    $start = microtime(true);
    $attendance = $zk->getAttendance();
    $elapsed = round(microtime(true) - $start, 2);
    echo "⏱️ Durée : {$elapsed}s | Nombre : " . count($attendance) . "\n\n";
    
    if (count($attendance) > 0) {
        echo "5 premiers pointages :\n";
        $i = 0;
        foreach ($attendance as $att) {
            if ($i++ >= 5) break;
            echo "  " . json_encode($att) . "\n";
        }
    } else {
        echo "⚠️ Aucun pointage dans getAttendance() non plus\n";
    }
    echo "\n";
    
    // 6. Pointages du jour
    echo "=== POINTAGES DU JOUR ===\n";
    $start = microtime(true);
    $today = $zk->getTodaysRecords();
    $elapsed = round(microtime(true) - $start, 2);
    echo "⏱️ Durée : {$elapsed}s | Nombre : " . count($today) . "\n\n";
    
    // 7. Test temps réel (rapide, sans attendre)
    echo "=== TEST EVENT MONITORING ===\n";
    echo "Démarrage du monitoring...\n";
    $zk->startEventMonitoring();
    echo "✅ Monitoring démarré\n";
    echo "Attente de 5 secondes d'événements...\n";
    sleep(5);
    $events = $zk->getRealTimeEvents();
    echo "Événements reçus : " . count($events) . "\n";
    $zk->stopEventMonitoring();
    echo "✅ Monitoring arrêté\n\n";
    
    // 8. Réactiver et déconnecter
    $zk->enableDevice();
    $zk->disconnect();
    echo "=== ✅ TEST COMPLET RÉUSSI ===\n";
    
} catch (Throwable $e) {
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . ":" . $e->getLine() . "\n";
}