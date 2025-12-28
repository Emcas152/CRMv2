<?php
/**
 * Verificar usuarios en la base de datos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    // Verificar si existe la tabla users
    $stmt = $db->query("SHOW TABLES LIKE 'users'");
    $tableExists = $stmt->fetch();
    
    if (!$tableExists) {
        echo json_encode([
            'error' => 'La tabla users no existe',
            'hint' => 'Necesitas ejecutar las migraciones de la base de datos'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Listar usuarios (sin mostrar contraseñas)
    $stmt = $db->query("SELECT id, name, email, role, created_at FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_users' => count($users),
        'users' => $users
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
