<?php
/**
 * Script de prueba para verificar CORS
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['message' => 'CORS preflight OK']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'CORS está funcionando correctamente',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'time' => date('Y-m-d H:i:s')
]);
