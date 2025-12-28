<?php
/**
 * Crear usuario administrador inicial
 * USAR SOLO UNA VEZ - Eliminar después
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    // Verificar si ya existe el usuario admin
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['superadmin@crmmedico.com']);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'message' => 'El usuario superadmin@crmmedico.com ya existe',
            'action' => 'Ninguna'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Crear usuario admin
    // Password: admin123 (hasheado con password_hash)
    $passwordHash = password_hash('superadmin123', PASSWORD_BCRYPT);
    
    $stmt = $db->prepare("
        INSERT INTO users (name, email, password, role, created_at, updated_at) 
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        'Administrador',
        'superadmin@crmmedico.com',
        $passwordHash,
        'superadmin'
    ]);
    
    $userId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Usuario administrador creado exitosamente',
        'user' => [
            'id' => $userId,
            'email' => 'superadmin@crmmedico.com',
            'password' => 'superadmin123',
            'role' => 'superadmin'
        ],
        'warning' => '⚠️ ELIMINA ESTE ARCHIVO (create-admin.php) DESPUÉS DE USAR'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
