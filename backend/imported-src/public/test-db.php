<?php
/**
 * Test de conexión a base de datos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar helper
require_once __DIR__ . '/../core/helpers.php';

header('Content-Type: application/json');

echo json_encode([
    'status' => 'testing',
    'env_loaded' => [
        'DB_HOST' => getenv('DB_HOST') ?: 'not set',
        'DB_NAME' => getenv('DB_NAME') ?: 'not set',
        'DB_USER' => getenv('DB_USER') ?: 'not set',
        'DB_PASS' => getenv('DB_PASS') ? '***set***' : 'not set',
    ],
    'config' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_NAME') ?: 'crm_spa_medico',
        'username' => getenv('DB_USER') ?: 'root',
    ],
    'env_file_exists' => file_exists(__DIR__ . '/../.env') ? 'yes' : 'no',
    'env_file_path' => realpath(__DIR__ . '/../.env') ?: 'not found',
], JSON_PRETTY_PRINT);

// Intentar conexión
try {
    $config = require __DIR__ . '/../config/database.php';
    
    $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
    echo "\n\nDSN: " . $dsn;
    print_r($config);
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    
    echo "\n\nConnection: SUCCESS\n";
    echo "Database: " . $config['database'];
    
} catch (PDOException $e) {
    echo "\n\nConnection: FAILED\n";
    echo "Error: " . $e->getMessage();
}
