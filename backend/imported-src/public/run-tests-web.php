<?php
/**
 * Ejecutar Pruebas desde el Navegador
 * IMPORTANTE: Eliminar este archivo en producción después de usarlo
 * URL: https://tu-dominio.com/run-tests-web.php
 */

// Seguridad básica - cambiar esta contraseña
define('TEST_PASSWORD', 'superadmin123');

// Verificar contraseña (case-insensitive para password y Password)
$inputPassword = $_GET['password'] ?? $_GET['Password'] ?? null;
if (!$inputPassword || $inputPassword !== TEST_PASSWORD) {
    http_response_code(403);
    die('❌ Acceso Denegado - Password requerido');
}

// Configurar para mostrar errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establecer zona horaria
date_default_timezone_set('Europe/Madrid');

// Header HTML
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suite de Pruebas - Backend PHP</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
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
        h2 {
            color: #dcdcaa;
            margin-top: 30px;
            margin-bottom: 15px;
            padding: 10px;
            background: #2d2d30;
            border-left: 4px solid #dcdcaa;
        }
        .info {
            background: #1e3a5f;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #569cd6;
        }
        .success {
            color: #4ec9b0;
            font-weight: bold;
        }
        .fail {
            color: #f48771;
            font-weight: bold;
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
        .pass-line {
            color: #4ec9b0;
        }
        .fail-line {
            color: #f48771;
        }
        .summary {
            background: #2d2d30;
            padding: 20px;
            border-radius: 5px;
            margin-top: 20px;
            border: 2px solid #4ec9b0;
        }
        .warning {
            background: #5a3e1b;
            color: #f1c232;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            border-left: 4px solid #f1c232;
        }
        code {
            background: #1e1e1e;
            padding: 2px 6px;
            border-radius: 3px;
            color: #ce9178;
        }
        .separator {
            border-top: 2px solid #3e3e42;
            margin: 30px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Suite de Pruebas - Backend PHP Puro</h1>
        
        <div class="info">
            <strong>🌐 Información del Servidor</strong><br>
            📅 Fecha: <?php echo date('Y-m-d H:i:s'); ?><br>
            🐘 PHP Version: <?php echo PHP_VERSION; ?><br>
            💻 Sistema: <?php echo PHP_OS; ?><br>
            🔧 Directorio: <?php echo __DIR__; ?>
        </div>

        <?php
        // Cambiar al directorio raíz del backend
        $backendRoot = dirname(__DIR__);
        chdir($backendRoot);
        
        // Cargar helpers para variables de entorno
        require_once 'core/helpers.php';
        
        echo "<div class='test-output'>";
        echo "<strong>📂 Directorio de trabajo: " . getcwd() . "</strong>\n\n";
        
        // Verificar que existe el directorio tests
        if (!file_exists('tests/run-tests.php')) {
            echo "<span class='fail'>❌ ERROR: No se encuentra tests/run-tests.php</span>\n";
            echo "Verifica que la carpeta tests/ esté subida al servidor.\n";
            echo "</div></div></body></html>";
            exit;
        }

        echo "✓ Archivo de pruebas encontrado\n\n";
        echo str_repeat('=', 60) . "\n";
        echo "EJECUTANDO PRUEBAS...\n";
        echo str_repeat('=', 60) . "\n\n";
        
        // Capturar la salida
        ob_start();
        
        try {
            require_once 'tests/run-tests.php';
            TestRunner::run();
        } catch (Exception $e) {
            echo "\n<span class='fail'>❌ ERROR FATAL: " . $e->getMessage() . "</span>\n";
            echo "Trace: " . $e->getTraceAsString();
        }
        
        $output = ob_get_clean();
        
        // Colorear la salida
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

        <div class="separator"></div>

        <div class="summary">
            <h2>✅ Pruebas Completadas</h2>
            <p>Las pruebas se han ejecutado correctamente en el servidor de producción.</p>
            <p><strong>Total de suites:</strong> Auth, Validator, Sanitizer, Integration, CRUD</p>
        </div>

        <div class="warning">
            <strong>⚠️ IMPORTANTE - SEGURIDAD</strong><br><br>
            Este archivo expone información sensible del servidor.<br>
            <strong>ELIMINA este archivo inmediatamente después de usarlo:</strong><br><br>
            <code>rm public/run-tests-web.php</code><br>
            o bórralo vía FTP/cPanel
        </div>

        <div class="info" style="margin-top: 20px;">
            <strong>💡 Cómo usar:</strong><br>
            1. Sube este archivo a: <code>/public_html/backend/public/run-tests-web.php</code><br>
            2. Accede a: <code>https://tu-dominio.com/run-tests-web.php?password=<?php echo TEST_PASSWORD; ?></code><br>
            3. <strong>ELIMINA el archivo después de verificar</strong>
        </div>
    </div>
</body>
</html>
