<?php
/**
 * Debug Token
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

header('Content-Type: application/json');

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

$token = Auth::getTokenFromHeader();
$user = null;
$tokenData = null;

if ($token) {
    $tokenData = Auth::verifyToken($token);
    $user = Auth::validateToken();
}

Response::json([
    'headers_received' => $headers,
    'auth_header' => $authHeader,
    'token_extracted' => $token,
    'token_parts' => $token ? explode('.', $token) : null,
    'token_valid' => $tokenData !== false,
    'token_data' => $tokenData,
    'user' => $user,
    'secret_key_loaded' => !empty(Auth::init())
]);
