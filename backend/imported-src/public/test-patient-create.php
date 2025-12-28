<?php
/**
 * Test endpoint para depurar creación de pacientes
 */

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

$results = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'raw_input' => file_get_contents('php://input'),
    'auth_header' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not set',
];

// Intentar decodificar JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

$results['decoded_input'] = $input;
$results['json_error'] = json_last_error_msg();

// Verificar autenticación
try {
    // Extraer token manualmente
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $token = null;
    
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
    
    $results['token_extracted'] = $token ? 'yes' : 'no';
    
    if ($token) {
        $userData = Auth::validateToken($token);
        $results['user_authenticated'] = $userData ? 'yes' : 'no';
        $results['user_data'] = $userData;
    }
} catch (Exception $e) {
    $results['auth_error'] = $e->getMessage();
}

// Test de inserción directa
if ($input && isset($input['name']) && isset($input['email'])) {
    try {
        $db = Database::getInstance();
        
        // Verificar email único
        $existing = $db->fetchOne('SELECT id FROM patients WHERE email = ?', [$input['email']]);
        $results['email_exists'] = $existing ? 'yes' : 'no';
        
        if (!$existing) {
            $result = $db->execute(
                'INSERT INTO patients (name, email, phone, birthday, address, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())',
                [
                    $input['name'],
                    $input['email'],
                    $input['phone'] ?? null,
                    $input['birthday'] ?? null,
                    $input['address'] ?? null
                ]
            );
            
            $results['insert_result'] = $result;
            $results['last_insert_id'] = $db->lastInsertId();
            $results['inserted'] = 'success';
        }
    } catch (Exception $e) {
        $results['db_error'] = $e->getMessage();
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
