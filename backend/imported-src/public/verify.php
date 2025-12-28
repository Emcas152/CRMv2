<?php
/**
 * Verificación rápida del backend
 */

// Información del servidor
$info = [
    'status' => 'ok',
    'message' => 'Backend PHP funcionando correctamente',
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
    'current_dir' => __DIR__,
];

// Verificar archivos críticos
$criticalFiles = [
    'index.php' => file_exists(__DIR__ . '/index.php'),
    'core/Database.php' => file_exists(__DIR__ . '/../core/Database.php'),
    'api/auth/login.php' => file_exists(__DIR__ . '/../api/auth/login.php'),
    '.env' => file_exists(__DIR__ . '/../.env'),
];

$info['files'] = $criticalFiles;
$info['all_files_ok'] = !in_array(false, $criticalFiles, true);

// Verificar mod_rewrite
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    $info['mod_rewrite'] = in_array('mod_rewrite', $modules);
}

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode($info, JSON_PRETTY_PRINT);
