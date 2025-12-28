<?php
/**
 * Archivo de configuración de base de datos generado automáticamente
 * Lee variables desde .env (cargadas por core/helpers.php)
 */

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: 3306;
$database = getenv('DB_NAME') ?: getenv('DB_DATABASE') ?: 'crm_spa_medico';
$username = getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => $charset,
];
