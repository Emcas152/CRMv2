<?php
/**
 * Ejecutar Solo Pruebas CRUD desde el Navegador
 * URL: https://tu-dominio.com/run-crud-test.php?password=superadmin123
 */

define('TEST_PASSWORD', 'superadmin123');

$inputPassword = $_GET['password'] ?? $_GET['Password'] ?? null;
if (!$inputPassword || $inputPassword !== TEST_PASSWORD) {
    http_response_code(403);
    die('❌ Acceso Denegado - Password requerido');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Europe/Madrid');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pruebas CRUD - Backend PHP</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Consolas', 'Monaco', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #252526;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 3px solid #4ec9b0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .info {
            background: #1e3a5f;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #569cd6;
        }
        .test-output {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
            border: 1px solid #3e3e42;
            white-space: pre-wrap;
            font-size: 14px;
        }
        .pass-line { color: #4ec9b0; }
        .fail-line { color: #f48771; }
        .warning {
            background: #5a3e1b;
            color: #f1c232;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            border-left: 4px solid #f1c232;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Pruebas CRUD - CREATE, READ, UPDATE, DELETE</h1>
        
        <div class="info">
            <strong>🌐 Información del Servidor</strong><br>
            📅 Fecha: <?php echo date('Y-m-d H:i:s'); ?><br>
            🐘 PHP Version: <?php echo PHP_VERSION; ?><br>
            💻 Sistema: <?php echo PHP_OS; ?><br>
            🔧 Directorio: <?php echo __DIR__; ?>
        </div>

        <?php
        $backendRoot = dirname(__DIR__);
        chdir($backendRoot);
        
        // Cargar helpers para variables de entorno
        require_once 'core/helpers.php';
        
        echo "<div class='test-output'>";
        echo "<strong>📂 Directorio de trabajo: " . getcwd() . "</strong>\n\n";
        
        if (!file_exists('tests/CrudTest.php')) {
            echo "<span class='fail'>❌ ERROR: No se encuentra tests/CrudTest.php</span>\n";
            echo "</div></div></body></html>";
            exit;
        }

        echo "✓ Archivo de pruebas CRUD encontrado\n\n";
        echo str_repeat('=', 60) . "\n";
        echo "EJECUTANDO PRUEBAS CRUD...\n";
        echo str_repeat('=', 60) . "\n\n";
        
        ob_start();
        
        try {
            require_once 'tests/CrudTest.php';
            CrudTest::runAll();
        } catch (Exception $e) {
            echo "\n<span class='fail'>❌ ERROR FATAL: " . $e->getMessage() . "</span>\n";
            echo "Trace: " . $e->getTraceAsString();
        }
        
        $output = ob_get_clean();
        
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (strpos($line, '✅') !== false || strpos($line, 'PASS') !== false) {
                echo "<span class='pass-line'>" . htmlspecialchars($line) . "</span>\n";
            } elseif (strpos($line, '❌') !== false || strpos($line, 'FAIL') !== false) {
                echo "<span class='fail-line'>" . htmlspecialchars($line) . "</span>\n";
            } else {
                echo htmlspecialchars($line) . "\n";
            }
        }
        
        echo "</div>";
        ?>

        <div class="warning">
            <strong>⚠️ IMPORTANTE - SEGURIDAD</strong><br><br>
            <strong>ELIMINA este archivo inmediatamente después de usarlo:</strong><br><br>
            <code>rm public/run-crud-test.php</code><br>
            o bórralo vía FTP/cPanel
        </div>
    </div>
</body>
</html>
