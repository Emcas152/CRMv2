<?php
/**
 * Punto de entrada principal de la API
 */

// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
// Define application log file constant for error handler
if (!defined('APP_LOG_FILE')) {
    define('APP_LOG_FILE', __DIR__ . '/../logs/error.log');
}

// Cargar variables de entorno
require_once __DIR__ . '/../core/helpers.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS - Configuración más permisiva para desarrollo
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Para desarrollo: permitir localhost y 127.0.0.1 en cualquier puerto
if (empty($origin) || 
    strpos($origin, 'http://localhost') === 0 || 
    strpos($origin, 'http://127.0.0.1') === 0 ||
    strpos($origin, 'https://localhost') === 0) {
    header("Access-Control-Allow-Origin: " . ($origin ?: 'http://localhost:4200'));
} else {
    // Para producción: verificar lista de orígenes permitidos
    $config = require __DIR__ . '/../config/app.php';
    $allowedOrigins = explode(',', $config['cors_origins']);
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: {$origin}");
    } else {
        // Also allow localhost origins even in production if present (helpful for local dev testing)
        if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
            header("Access-Control-Allow-Origin: {$origin}");
        }
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Cargar clases del core
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../core/Sanitizer.php';
require_once __DIR__ . '/../core/ErrorHandler.php';

$method = $_SERVER['REQUEST_METHOD'];
// Allow method override via header, _method param or JSON body when proxies/servers block PUT/DELETE
// Common clients may send 'X-HTTP-Method-Override' header or include '_method' in POST body/query
if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
} elseif (isset($_REQUEST['_method']) && !empty($_REQUEST['_method'])) {
    $method = strtoupper($_REQUEST['_method']);
} else {
    // Also accept JSON body containing _method (useful when frontend sends JSON payload)
    $rawBody = @file_get_contents('php://input');
    if ($rawBody) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) && isset($decoded['_method']) && !empty($decoded['_method'])) {
            $method = strtoupper($decoded['_method']);
        }
    }
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remover cualquier /index.php en la ruta (permite llamadas como index.php/api/... cuando mod_rewrite no está activo)
$uri = preg_replace('#/index\.php#', '', $uri);

// Normalizar URI - remover el prefijo de carpetas del servidor
// Soporta: /api/login, /public/api/login, /backend/public/api/login, /crm/backend/public/api/login
// También normaliza llamadas que incluyen el prefijo completo del servidor: /crm/backend/public/login
$uri = preg_replace('#^/crm/backend/public/api#', '', $uri);
$uri = preg_replace('#^/crm/backend/public#', '', $uri);
$uri = preg_replace('#^/(backend/)?public/api#', '', $uri);
$uri = preg_replace('#^/(backend/)?public#', '', $uri);
$uri = preg_replace('#^/api#', '', $uri);
$uri = rtrim($uri, '/');

// Debug (remover después de verificar)
if (isset($_GET['debug'])) {
    Response::json([
        'REQUEST_URI' => $_SERVER['REQUEST_URI'],
        'parsed_uri' => $uri,
        'method' => $method
    ]);
    exit;
}

    // Health / server identity endpoint (useful to verify which backend responds)
    if ($uri === '/__server_info' && $method === 'GET') {
        Response::success(['backend' => 'php-puro', 'version' => '1.0'], 'Server info');
        exit;
    }

    // Email verification endpoint
    if ($uri === '/verify-email' && in_array($method, ['GET', 'POST'])) {
        require __DIR__ . '/../api/auth/verify-email.php';
        exit;
    }

// Obtener datos de entrada
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
} else {
    $input = $_REQUEST;
}

// Sanitizar datos de entrada
$input = Sanitizer::input($input);

// Router simple
try {
    // Rutas de autenticación (públicas)
    if ($uri === '/login' && $method === 'POST') {
        require __DIR__ . '/../api/auth/login.php';
        exit;
    }

    if ($uri === '/register' && $method === 'POST') {
        require __DIR__ . '/../api/auth/register.php';
        exit;
    }

    if ($uri === '/me' && $method === 'GET') {
        require __DIR__ . '/../api/auth/me.php';
        exit;
    }

    if ($uri === '/logout' && $method === 'POST') {
        require __DIR__ . '/../api/auth/logout.php';
        exit;
    }

    // Profile routes (protected)
    if (preg_match('#^/profile(/([a-z-]+))?$#', $uri, $matches)) {
        $action = $matches[2] ?? null;
        require __DIR__ . '/../api/profile/index.php';
        exit;
    }
    
    // Debug token
    if ($uri === '/debug-token' && $method === 'GET') {
        require __DIR__ . '/../api/auth/debug-token.php';
        exit;
    }

    // Rutas de pacientes (protegidas)
    if (preg_match('#^/patients(/(\d+))?(/([a-z-]+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        $action = $matches[4] ?? null;
        require __DIR__ . '/../api/patients/index.php';
        exit;
    }

    // Rutas de productos (protegidas)
    if (preg_match('#^/products(/(\d+))?(/([a-z-]+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        $action = $matches[4] ?? null;
        require __DIR__ . '/../api/products/index.php';
        exit;
    }

    // Rutas de documentos (protegidas)
    if (preg_match('#^/documents(/(\d+))?(/([a-z-]+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        $action = $matches[4] ?? null;
        require __DIR__ . '/../api/documents/index.php';
        exit;
    }

    // Rutas de citas (protegidas)
    if (preg_match('#^/appointments(/(\d+))?(/([a-z-]+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        $action = $matches[4] ?? null;
        require __DIR__ . '/../api/appointments/index.php';
        exit;
    }

    // Rutas de ventas (protegidas)
    if (preg_match('#^/sales(/(\d+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        require __DIR__ . '/../api/sales/index.php';
        exit;
    }

    // Rutas de usuarios (protegidas - solo superadmin y admin)
    if (preg_match('#^/users(/(\d+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        require __DIR__ . '/../api/users/index.php';
        exit;
    }

    // Rutas de email templates (protegidas - solo superadmin y admin)
    if (preg_match('#^/email-templates(/(\d+))?$#', $uri, $matches)) {
        $id = $matches[2] ?? null;
        require __DIR__ . '/../api/email-templates/index.php';
        exit;
    }

    // Ruta de dashboard (protegida)
    if ($uri === '/dashboard/stats' && $method === 'GET') {
        require __DIR__ . '/../api/dashboard/stats.php';
        exit;
    }

    // Debug dashboard
    if ($uri === '/dashboard/debug-stats' && $method === 'GET') {
        require __DIR__ . '/../api/dashboard/debug-stats.php';
        exit;
    }

    // Ruta no encontrada
    Response::notFound('Endpoint no encontrado');

} catch (Throwable $e) {
    // Delegate to centralized error handler which logs and returns a safe JSON payload
    ErrorHandler::handle($e);
}
