<?php

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap CI4 minimal (méthode 1)
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

echo "=== Test connexion GPIO ===\n\n";

try {
    $gpioDb = (new \App\Services\ApplicationIntegrationSettings())->gpioConnection();
    echo "✅ Connexion établie\n\n";
    
    $count = $gpioDb->table('etau_service')->countAllResults();
    echo "Services GPIO total : {$count}\n";
    
    $repartition = $gpioDb->table('etau_service')
        ->select('wich_is, COUNT(*) as nb')
        ->where('status', 1)
        ->where('wich_is IS NOT NULL')
        ->groupBy('wich_is')
        ->get()
        ->getResultArray();
    
    echo "\nRépartition par type :\n";
    foreach ($repartition as $row) {
        echo "  - '{$row['wich_is']}' : {$row['nb']}\n";
    }
    
    $countUsers = $gpioDb->table('etau_utilisateur')
        ->where('typeObjet', 'U')
        ->where('utilisateur_active', 1)
        ->countAllResults();
    echo "\nUtilisateurs GPIO actifs : {$countUsers}\n";
    
    $countChefs = $gpioDb->table('etau_service_chef')
        ->where('estactif', 1)
        ->countAllResults();
    echo "Chefs actifs : {$countChefs}\n";
    
    echo "\n✅ Test réussi\n";
    
} catch (\Throwable $e) {
    echo "❌ ERREUR : " . $e->getMessage() . "\n";
    echo "Fichier : " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}