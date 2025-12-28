<?php
require_once __DIR__ . '/../bootstrap.php';

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
