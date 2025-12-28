<?php
/**
 * Script para crear usuario Superadmin
 * Ejecutar este script para crear el usuario superadmin
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();

    // Datos del superadmin
    $email = 'superadmin@crm.com';
    $password = 'superadmin123';
    $name = 'Super Administrador';
    $role = 'superadmin';

    // Verificar si ya existe
    $stmt = $db->prepare("SELECT id, email, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Si existe, actualizar la contraseña
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare("
            UPDATE users
            SET password = ?, role = ?, name = ?, updated_at = NOW()
            WHERE email = ?
        ");
        $stmt->execute([$hashedPassword, $role, $name, $email]);

        echo json_encode([
            'success' => true,
            'message' => 'Superadmin actualizado exitosamente',
            'user' => [
                'id' => $existing['id'],
                'email' => $email,
                'role' => $role,
                'password' => $password . ' (plain text - solo para referencia)'
            ]
        ], JSON_PRETTY_PRINT);
    } else {
        // Crear nuevo
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare("
            INSERT INTO users (name, email, password, role, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$name, $email, $hashedPassword, $role]);

        $userId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Superadmin creado exitosamente',
            'user' => [
                'id' => $userId,
                'email' => $email,
                'role' => $role,
                'password' => $password . ' (plain text - solo para referencia)'
            ]
        ], JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
