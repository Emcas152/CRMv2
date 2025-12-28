<?php
/**
 * Test simple de API con CORS
 */

// CORS headers - igual que en index.php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (empty($origin) || 
    strpos($origin, 'http://localhost') === 0 || 
    strpos($origin, 'http://127.0.0.1') === 0 ||
    strpos($origin, 'https://localhost') === 0) {
    header("Access-Control-Allow-Origin: " . ($origin ?: 'http://localhost:4200'));
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'API Test OK - CORS funcionando',
    'origin' => $origin,
    'method' => $_SERVER['REQUEST_METHOD'],
    'request_uri' => $_SERVER['REQUEST_URI'],
    'time' => date('Y-m-d H:i:s')
]);
