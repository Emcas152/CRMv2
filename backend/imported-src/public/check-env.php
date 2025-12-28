<?php
/**
 * Verificar qué archivo .env se está cargando
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Verificación de Archivos .env</h2>";

$baseDir = __DIR__ . '/../';

echo "<h3>Archivos existentes:</h3>";
echo ".env existe: " . (file_exists($baseDir . '.env') ? 'SÍ' : 'NO') . "<br>";
echo ".env.local existe: " . (file_exists($baseDir . '.env.local') ? 'SÍ' : 'NO') . "<br><br>";

// Cargar helpers
require_once __DIR__ . '/../core/helpers.php';

echo "<h3>Variables de entorno después de cargar helpers.php:</h3>";
echo "DB_HOST: " . (getenv('DB_HOST') ?: 'NO DEFINIDO') . "<br>";
echo "DB_NAME: " . (getenv('DB_NAME') ?: 'NO DEFINIDO') . "<br>";
echo "DB_USER: " . (getenv('DB_USER') ?: 'NO DEFINIDO') . "<br>";
echo "DB_PASS: " . (getenv('DB_PASS') !== false ? (empty(getenv('DB_PASS')) ? '(VACÍO)' : '***SET***') : 'NO DEFINIDO') . "<br>";
echo "APP_ENV: " . (getenv('APP_ENV') ?: 'NO DEFINIDO') . "<br><br>";

// Leer manualmente .env.local
echo "<h3>Contenido de .env.local (primeras líneas):</h3>";
if (file_exists($baseDir . '.env.local')) {
    $lines = file($baseDir . '.env.local', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_slice($lines, 0, 15) as $line) {
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
            continue;
        }
        // Ocultar contraseñas
        if (strpos($line, 'PASS') !== false || strpos($line, 'SECRET') !== false) {
            $parts = explode('=', $line, 2);
            echo htmlspecialchars($parts[0]) . "=***<br>";
        } else {
            echo htmlspecialchars($line) . "<br>";
        }
    }
} else {
    echo "Archivo no encontrado<br>";
}

echo "<br><h3>Contenido de .env (primeras líneas):</h3>";
if (file_exists($baseDir . '.env')) {
    $lines = file($baseDir . '.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_slice($lines, 0, 15) as $line) {
        if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
            continue;
        }
        // Ocultar contraseñas
        if (strpos($line, 'PASS') !== false || strpos($line, 'SECRET') !== false) {
            $parts = explode('=', $line, 2);
            echo htmlspecialchars($parts[0]) . "=***<br>";
        } else {
            echo htmlspecialchars($line) . "<br>";
        }
    }
} else {
    echo "Archivo no encontrado<br>";
}
