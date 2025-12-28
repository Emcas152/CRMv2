<?php
/**
 * Debug Dashboard Stats
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

header('Content-Type: application/json');

try {
    // Test 1: Auth
    echo json_encode(['step' => 1, 'message' => 'Cargando clases...']) . "\n";
    
    // Test 2: Get headers
    $headers = getallheaders();
    echo json_encode(['step' => 2, 'headers' => $headers]) . "\n";
    
    // Test 3: Validate token
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    echo json_encode(['step' => 3, 'auth_header' => $authHeader]) . "\n";
    
    if (!$authHeader) {
        echo json_encode(['error' => 'No Authorization header']) . "\n";
        exit;
    }
    
    // Test 4: Validate
    $user = Auth::validateToken();
    echo json_encode(['step' => 4, 'user' => $user]) . "\n";
    
    if (!$user) {
        echo json_encode(['error' => 'Token validation failed']) . "\n";
        exit;
    }
    
    // Test 5: Database
    $db = Database::getInstance();
    echo json_encode(['step' => 5, 'message' => 'Database connected']) . "\n";
    
    // Test 6: Show tables
    $pdo = $db->getConnection();
    $stmt = $pdo->query("SHOW TABLES");
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    echo json_encode(['step' => 6, 'tables' => $tables]) . "\n";
    
    echo json_encode(['success' => true, 'message' => 'All tests passed']) . "\n";
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]) . "\n";
}
