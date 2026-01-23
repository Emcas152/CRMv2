<?php
require_once __DIR__ . '/../bootstrap.php';

// ============================================================================
// PHASE 2: Rate Limiting Middleware (with fail-safe)
// ============================================================================
require_once __DIR__ . '/../app/Core/RateLimiter.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Auth.php';

use App\Core\RateLimiter;
use App\Core\Database;
use App\Core\Auth;

// Rate limiting with fail-safe - if it fails, allow the request through
$rateLimitResult = null;
try {
    // Inicializar rate limiter
    $rateLimiter = new RateLimiter();

    // Obtener user ID si está autenticado (para rate limit por usuario)
    $userId = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        try {
            $payload = Auth::parseToken($matches[1]);
            $userId = $payload['user_id'] ?? null;
        } catch (\Exception $e) {
            // Token inválido, continuar sin user_id
        }
    }

    // Verificar rate limit
    $rateLimitResult = $rateLimiter->handle($userId);
} catch (\Throwable $e) {
    // Log error but allow request through (fail-open)
    error_log('RateLimiter error (allowing request): ' . $e->getMessage());
    $rateLimitResult = true;
}

if ($rateLimitResult === false) {
    // Excedió rate limit
    header('HTTP/1.1 429 Too Many Requests');
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Too many requests. Please try again later.',
        'message' => 'Has excedido el límite de solicitudes. Por favor intente más tarde.'
    ]);
    exit;
}

// ============================================================================
// Continuar con configuración normal
// ============================================================================

$config = @include __DIR__ . '/../config/app.php';
$corsOrigins = $config['cors_origins'] ?? '*';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($corsOrigins === '*' || $corsOrigins === null || trim((string) $corsOrigins) === '') {
    header('Access-Control-Allow-Origin: *');
} else {
    $allowed = array_map('trim', explode(',', (string) $corsOrigins));
    if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
    }
}
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_SERVER['HTTP_X_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }

    if (is_string($override) && $override !== '') {
        $override = strtoupper(trim($override));
        if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
            $method = $override;
            $_SERVER['REQUEST_METHOD'] = $override;
        }
    }
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If deployed under a subfolder (e.g. https://example.com/backend),
// REQUEST_URI includes that prefix; strip it so routes can stay at /api/v1/...
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = rtrim($scriptDir, '/');
if ($scriptDir !== '' && $scriptDir !== '.' && $scriptDir !== '/') {
    if (strpos($uri, $scriptDir) === 0) {
        $uri = substr($uri, strlen($scriptDir));
        if ($uri === '') {
            $uri = '/';
        }
    }
}

require_once __DIR__ . '/../routes/api.php';
