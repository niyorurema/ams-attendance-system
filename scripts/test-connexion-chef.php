<?php

use App\Services\LdapAuthService;
use Config\Database;
use Config\Paths;
use CodeIgniter\Boot;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Ce script doit être exécuté en CLI.\n");
    exit(1);
}

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Paths.php';
$paths = new Paths();
require $paths->systemDirectory . DIRECTORY_SEPARATOR . 'Boot.php';
Boot::preload($paths);
Boot::bootConsole($paths);

try {
    $db = Database::connect();
    $compte = $db->table('users')
        ->select('id, tenant_id, email')
        ->where('tenant_id', 1)
        ->where('auth_source', 'LDAP')
        ->where('is_active', 1)
        ->where('email IS NOT NULL', null, false)
        ->where('email !=', '')
        ->where('deleted_at', null)
        ->orderBy('id', 'ASC')
        ->get(1)
        ->getRowArray();

    if ($compte === null) {
        fwrite(STDERR, "Aucun compte chef LDAP actif à vérifier.\n");
        exit(1);
    }

    $existeDansAd = (new LdapAuthService($db))->emailExisteDansAd(
        (int) $compte['tenant_id'],
        (string) $compte['email']
    );

    echo "=== Vérification d'un compte chef LDAP ===\n";
    echo "Connexion au contrôleur AD : OK\n";
    echo "Adresse du compte AMS trouvée dans AD : " . ($existeDansAd ? "oui\n" : "non\n");
    echo "Aucun email, nom, DN, identifiant ou mot de passe n'a été affiché.\n";
    echo "Cette vérification ne teste pas le mot de passe de l'utilisateur; faites un essai via l'écran de connexion avec son accord.\n";

    exit($existeDansAd ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, "Vérification LDAP impossible : " . $exception->getMessage() . "\n");
    exit(1);
}
