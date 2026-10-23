<?php

echo "=== VÉRIFICATION DES DÉPENDANCES ===\n\n";

// Extensions PHP
$extensions = ['ldap', 'intl', 'curl', 'mbstring', 'json', 'mysqli', 'gd', 'zip'];
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? '✅' : '❌';
    echo "$status $ext\n";
}

// Composer
require __DIR__ . '/../vendor/autoload.php';
echo "\n=== PACKAGES COMPOSER ===\n";
echo (class_exists('PHPMailer\PHPMailer\PHPMailer') ? '✅' : '❌') . " PHPMailer\n";
echo (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet') ? '✅' : '❌') . " PhpSpreadsheet\n";
echo (class_exists('Mpdf\Mpdf') ? '✅' : '❌') . " mPDF\n";

// Version PHP
echo "\n=== VERSION PHP ===\n";
echo "PHP : " . PHP_VERSION . "\n";
echo "memory_limit : " . ini_get('memory_limit') . "\n";