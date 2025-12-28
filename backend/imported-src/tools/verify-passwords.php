<?php
// Script CLI para verificar que los passwords almacenados correspondan a hashes válidos
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

$map = [
    'superadmin@crm.com' => 'superadmin123',
    'admin@crm.com' => 'admin123',
    'doctor@crm.com' => 'doctor123',
    'staff@crm.com' => 'staff123',
    'patient@crm.com' => 'patient123'
];

try {
    $db = Database::getInstance();
    $rows = $db->fetchAll('SELECT id, email, password FROM users');
} catch (Exception $e) {
    echo "Error al conectar o leer usuarios: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$allOk = true;
foreach ($rows as $r) {
    $email = $r['email'];
    $hash = $r['password'];

    // Comprobar formato del hash: bcrypt comienza por $2y$ o $2b$ y tiene longitud típica
    $isBcrypt = preg_match('/^\$2[abxy]\$\d{2}\$.{53}$/', $hash) === 1;

    $knownPlain = $map[$email] ?? null;
    $verified = null;
    if ($knownPlain !== null) {
        $verified = password_verify($knownPlain, $hash);
    }

    echo "User: $email" . PHP_EOL;
    echo "  Hash looks like bcrypt: " . ($isBcrypt ? 'YES' : 'NO') . PHP_EOL;
    if ($knownPlain !== null) {
        echo "  Verify against known password: " . ($verified ? 'MATCH' : 'NO MATCH') . PHP_EOL;
    } else {
        echo "  No known plaintext to verify" . PHP_EOL;
    }

    if (!$isBcrypt || ($knownPlain !== null && !$verified)) {
        $allOk = false;
    }
}

if ($allOk) {
    echo "\nAll checked passwords look bcrypt and verified where plaintext was known.\n";
    exit(0);
} else {
    echo "\nSome passwords failed checks. Review output above.\n";
    exit(2);
}
