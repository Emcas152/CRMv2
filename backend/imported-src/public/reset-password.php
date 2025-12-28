<?php
/**
 * Resetear contraseña del administrador
 * USAR SOLO CUANDO SEA NECESARIO - Eliminar después
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    // Verificar si existe el usuario admin
    $stmt = $db->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute(['admin@crmmedico.com']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'error' => 'El usuario admin@crmmedico.com no existe'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Nueva contraseña: admin123
    $newPassword = 'admin123';
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    
    // Actualizar contraseña
    $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?");
    $stmt->execute([$passwordHash, 'admin@crmmedico.com']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Contraseña actualizada exitosamente',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'new_password' => $newPassword
        ],
        'password_hash' => $passwordHash,
        'warning' => '⚠️ ELIMINA ESTE ARCHIVO (reset-password.php) DESPUÉS DE USAR'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
