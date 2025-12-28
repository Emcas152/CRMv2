<?php
/**
 * Verificador de Requisitos del Servidor
 * Ejecutar antes de las pruebas para asegurar que todo está configurado
 * 
 * Uso: php check-server.php
 */

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║     VERIFICADOR DE REQUISITOS - BACKEND PHP PURO         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$allGood = true;
$warnings = [];
$errors = [];

// ============================================================
// 1. PHP VERSION
// ============================================================
echo "📌 Verificando PHP Version...\n";
$phpVersion = phpversion();
$minVersion = '7.4.0';

if (version_compare($phpVersion, $minVersion, '>=')) {
    echo "   ✅ PHP Version: $phpVersion (Requerido: $minVersion+)\n";
} else {
    echo "   ❌ PHP Version: $phpVersion (Muy antigua, necesitas $minVersion+)\n";
    $errors[] = "PHP version muy antigua";
    $allGood = false;
}
echo "\n";

// ============================================================
// 2. EXTENSIONES PHP
// ============================================================
echo "📌 Verificando Extensiones PHP...\n";

$requiredExtensions = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'json' => 'JSON',
    'mbstring' => 'Multibyte String'
];

foreach ($requiredExtensions as $ext => $name) {
    if (extension_loaded($ext)) {
        echo "   ✅ $name: Instalada\n";
    } else {
        echo "   ❌ $name: NO instalada\n";
        $errors[] = "Extensión $name faltante";
        $allGood = false;
    }
}
echo "\n";

// ============================================================
// 3. ARCHIVOS CORE
// ============================================================
echo "📌 Verificando Archivos Core...\n";

$coreFiles = [
    'core/Database.php',
    'core/Auth.php',
    'core/Validator.php',
    'core/Sanitizer.php',
    'config/database.php'
];

foreach ($coreFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ $file: Existe\n";
    } else {
        echo "   ❌ $file: NO encontrado\n";
        $errors[] = "Archivo $file faltante";
        $allGood = false;
    }
}
echo "\n";

// ============================================================
// 4. ARCHIVOS DE PRUEBAS
// ============================================================
echo "📌 Verificando Archivos de Pruebas...\n";

$testFiles = [
    'tests/run-tests.php',
    'tests/AuthTest.php',
    'tests/ValidatorTest.php',
    'tests/SanitizerTest.php',
    'tests/ApiIntegrationTest.php',
    'tests/CrudTest.php'
];

$testsFound = 0;
foreach ($testFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ $file: Existe\n";
        $testsFound++;
    } else {
        echo "   ⚠️  $file: NO encontrado\n";
        $warnings[] = "Archivo de prueba $file faltante";
    }
}

if ($testsFound === 0) {
    echo "   ❌ NO se encontraron archivos de pruebas\n";
    $errors[] = "Directorio tests/ vacío o faltante";
    $allGood = false;
}
echo "\n";

// ============================================================
// 5. CONEXIÓN A BASE DE DATOS
// ============================================================
echo "📌 Verificando Conexión a Base de Datos...\n";

if (file_exists('config/database.php')) {
    $config = require 'config/database.php';
    
    echo "   📋 Configuración:\n";
    echo "      Host: {$config['host']}\n";
    echo "      Port: {$config['port']}\n";
    echo "      Database: {$config['database']}\n";
    echo "      Username: {$config['username']}\n";
    echo "      Password: " . (empty($config['password']) ? 'vacío' : str_repeat('*', 8)) . "\n\n";
    
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        echo "   ✅ Conexión exitosa a la base de datos\n";
        
        // Verificar tablas
        echo "\n   📊 Verificando Tablas...\n";
        $tables = ['users', 'patients', 'products', 'appointments'];
        $tablesFound = 0;
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "      ✅ Tabla '$table': Existe\n";
                $tablesFound++;
            } else {
                echo "      ⚠️  Tabla '$table': NO encontrada\n";
                $warnings[] = "Tabla $table no existe";
            }
        }
        
        if ($tablesFound === count($tables)) {
            echo "\n   ✅ Todas las tablas necesarias existen\n";
        } elseif ($tablesFound > 0) {
            echo "\n   ⚠️  Algunas tablas faltan\n";
        } else {
            echo "\n   ❌ NO se encontraron tablas\n";
            $errors[] = "Base de datos vacía";
            $allGood = false;
        }
        
    } catch (PDOException $e) {
        echo "   ❌ Error de conexión: " . $e->getMessage() . "\n";
        $errors[] = "No se puede conectar a la base de datos";
        $allGood = false;
    }
} else {
    echo "   ❌ Archivo config/database.php NO encontrado\n";
    $errors[] = "Configuración de BD faltante";
    $allGood = false;
}
echo "\n";

// ============================================================
// 6. PERMISOS DE ESCRITURA
// ============================================================
echo "📌 Verificando Permisos...\n";

$writableDirs = [
    'storage/logs',
    'uploads'
];

foreach ($writableDirs as $dir) {
    if (file_exists($dir)) {
        if (is_writable($dir)) {
            echo "   ✅ $dir: Escribible\n";
        } else {
            echo "   ⚠️  $dir: NO escribible\n";
            $warnings[] = "Directorio $dir sin permisos de escritura";
        }
    } else {
        echo "   ⚠️  $dir: NO existe (puede no ser necesario)\n";
    }
}
echo "\n";

// ============================================================
// 7. INFORMACIÓN DEL SISTEMA
// ============================================================
echo "📌 Información del Sistema...\n";
echo "   🖥️  Sistema Operativo: " . PHP_OS . "\n";
echo "   📂 Directorio Actual: " . getcwd() . "\n";
echo "   💾 Memoria Límite: " . ini_get('memory_limit') . "\n";
echo "   ⏱️  Max Execution Time: " . ini_get('max_execution_time') . "s\n";
echo "   📁 Upload Max Size: " . ini_get('upload_max_filesize') . "\n";
echo "\n";

// ============================================================
// RESUMEN FINAL
// ============================================================
echo str_repeat('=', 60) . "\n";
echo "RESUMEN FINAL\n";
echo str_repeat('=', 60) . "\n\n";

if ($allGood && empty($warnings)) {
    echo "🎉 ¡TODO ESTÁ PERFECTO!\n\n";
    echo "✅ Todos los requisitos están cumplidos\n";
    echo "✅ El servidor está listo para ejecutar las pruebas\n\n";
    echo "Para ejecutar las pruebas:\n";
    echo "   php tests/run-tests.php\n\n";
    exit(0);
} elseif ($allGood && !empty($warnings)) {
    echo "⚠️  EL SISTEMA FUNCIONARÁ PERO HAY ADVERTENCIAS\n\n";
    echo "✅ Los requisitos críticos están cumplidos\n";
    echo "⚠️  Advertencias encontradas:\n";
    foreach ($warnings as $i => $warning) {
        echo "   " . ($i + 1) . ". $warning\n";
    }
    echo "\nPuedes ejecutar las pruebas pero revisa las advertencias:\n";
    echo "   php tests/run-tests.php\n\n";
    exit(0);
} else {
    echo "❌ HAY PROBLEMAS QUE DEBEN RESOLVERSE\n\n";
    echo "Errores críticos encontrados:\n";
    foreach ($errors as $i => $error) {
        echo "   " . ($i + 1) . ". $error\n";
    }
    if (!empty($warnings)) {
        echo "\nAdvertencias adicionales:\n";
        foreach ($warnings as $i => $warning) {
            echo "   " . ($i + 1) . ". $warning\n";
        }
    }
    echo "\n⚠️  Resuelve estos problemas antes de ejecutar las pruebas\n\n";
    exit(1);
}
