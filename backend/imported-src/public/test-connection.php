<?php
/**
 * Test de conexión simple
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar helpers
require_once __DIR__ . '/../core/helpers.php';

echo "<h2>Test de Conexión a Base de Datos</h2>";

echo "<h3>Variables de entorno:</h3>";
echo "DB_HOST: " . getenv('DB_HOST') . "<br>";
echo "DB_NAME: " . getenv('DB_NAME') . "<br>";
echo "DB_USER: " . getenv('DB_USER') . "<br>";
echo "DB_PASS: " . (getenv('DB_PASS') === '' ? '(VACÍO)' : '***SET***') . "<br><br>";

// Cargar configuración
$config = require __DIR__ . '/../config/database.php';

echo "<h3>Configuración cargada:</h3>";
echo "host: " . $config['host'] . "<br>";
echo "database: " . $config['database'] . "<br>";
echo "username: " . $config['username'] . "<br>";
echo "password: " . ($config['password'] === '' ? '(VACÍO)' : '***SET***') . "<br><br>";

// Intentar conexión
echo "<h3>Intentando conectar...</h3>";

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['database'],
        $config['charset']
    );
    
    echo "DSN: " . $dsn . "<br><br>";
    
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<p style='color: green; font-weight: bold;'>✅ CONEXIÓN EXITOSA</p>";
    
    // Probar una consulta
    $stmt = $pdo->query("SELECT DATABASE() as db, VERSION() as version");
    $result = $stmt->fetch();
    
    echo "Base de datos actual: " . $result['db'] . "<br>";
    echo "Versión MySQL: " . $result['version'] . "<br>";
    
} catch (PDOException $e) {
    echo "<p style='color: red; font-weight: bold;'>❌ ERROR DE CONEXIÓN</p>";
    echo "Mensaje: " . $e->getMessage() . "<br>";
    echo "Código: " . $e->getCode();
}
