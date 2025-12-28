<?php
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

$email = 'superadmin@crm.com';
$plain = 'superadmin123';

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo "Error al conectar a la base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$existing = $db->fetchOne('SELECT id, email FROM users WHERE email = ? LIMIT 1', [$email]);
$hash = Auth::hashPassword($plain);

if ($existing) {
    // Actualizar contraseña si ya existe
    $updateSql = 'UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?';
    $rows = $db->execute($updateSql, [$hash, $email]);
    if ($rows >= 0) {
        echo "Usuario existente actualizado: {$email} (password reseteada)" . PHP_EOL;
        exit(0);
    } else {
        echo "Error al actualizar la contraseña para: {$email}" . PHP_EOL;
        exit(2);
    }
} else {
    $name = 'Super Administrador';
    $role = 'superadmin';
    $phone = '+502 0000-0000';
    $active = 1;

    $sql = "INSERT INTO users (name, email, password, role, phone, active) VALUES (?, ?, ?, ?, ?, ?)";
    $rows = $db->execute($sql, [$name, $email, $hash, $role, $phone, $active]);

    if ($rows > 0) {
        echo "Superadmin creado: {$email}" . PHP_EOL;
        exit(0);
    } else {
        echo "No se pudo crear superadmin." . PHP_EOL;
        exit(2);
    }
}
