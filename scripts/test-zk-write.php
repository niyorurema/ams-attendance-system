<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\ZKTecoPullClient;

$ip = '10.100.28.230';
$port = 4370;

echo "=== TEST ÉCRITURE ZK ===\n";
echo "Capteur : $ip:$port\n\n";

$client = new ZKTecoPullClient($ip, $port, true, 30);

try {
    $client->connect();
    echo "[1] Connexion OK\n";
    
    $client->disableDevice();
    echo "[2] Device désactivé\n";
    
    // Test 1 : setUser
    echo "\n[3] Test setUser(uid=9999, userid=TEST9999)...\n";
    $result = $client->setUser(9999, 'TEST9999', 'TEST USER', '', 0, '');
    echo "    Résultat : " . ($result ? '✅ OK' : '❌ ÉCHEC') . "\n";
    
    if (!$result) {
        throw new RuntimeException("setUser a échoué");
    }
    
    // Test 2 : updateUser
    echo "\n[4] Test updateUser(uid=9999, name='TEST UPDATED')...\n";
    $result = $client->updateUser(9999, [
        'user_id' => 'TEST9999',
        'name' => 'TEST UPDATED',
        'role' => 0,
    ]);
    echo "    Résultat : " . ($result ? '✅ OK' : '❌ ÉCHEC') . "\n";
    
    // Test 3 : removeUser
    echo "\n[5] Test removeUser(uid=9999)...\n";
    $result = $client->removeUser(9999);
    echo "    Résultat : " . ($result ? '✅ OK' : '❌ ÉCHEC') . "\n";
    
    $client->enableDevice();
    $client->disconnect();
    
    echo "\n=== ✅ TEST COMPLET RÉUSSI ===\n";
    
} catch (Throwable $e) {
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    echo "Ligne : " . $e->getFile() . ":" . $e->getLine() . "\n";
    try {
        $client->enableDevice();
        $client->disconnect();
    } catch (Throwable $ignored) {}
}