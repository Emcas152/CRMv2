<?php
require_once __DIR__ . '/../core/helpers.php';

header('Content-Type: application/json');

try {
    $config = require __DIR__ . '/../config/database.php';
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']}",
        $config['username'],
        $config['password']
    );
    
    $stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE email='superadminadmin@crm.com'");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode([
            'status' => 'found',
            'user' => $user,
            'message' => 'Usuario admin existe'
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'status' => 'not_found',
            'message' => 'Usuario admin NO existe. Ejecuta fix-db.php para crearlo.'
        ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
