<?php
/**
 * Suite de Pruebas Completa
 * Ejecuta todas las pruebas del sistema
 */

// Cargar helpers para variables de entorno
require_once __DIR__ . '/../core/helpers.php';

// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Establecer zona horaria
date_default_timezone_set('Europe/Madrid');

// Cargar clases de prueba
require_once __DIR__ . '/AuthTest.php';
require_once __DIR__ . '/ValidatorTest.php';
require_once __DIR__ . '/SanitizerTest.php';

class TestRunner
{
    private static $totalTests = 0;
    private static $totalPassed = 0;
    private static $totalFailed = 0;
    private static $startTime;
    private static $suites = [];

    public static function run()
    {
        self::$startTime = microtime(true);
        
        self::printHeader();
        
        // Ejecutar todas las suites de pruebas
        self::runSuite('Auth', 'AuthTest');
        self::runSuite('Validator', 'ValidatorTest');
        self::runSuite('Sanitizer', 'SanitizerTest');
        
        // Pruebas de integración (requieren base de datos)
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "PRUEBAS DE INTEGRACIÓN\n";
        echo str_repeat('=', 60) . "\n";
        require_once __DIR__ . '/ApiIntegrationTest.php';
        ApiIntegrationTest::runAll();
        
        // Pruebas CRUD (requieren base de datos)
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "PRUEBAS CRUD (CREATE, READ, UPDATE, DELETE)\n";
        echo str_repeat('=', 60) . "\n";
        require_once __DIR__ . '/CrudTest.php';
        CrudTest::runAll();
        
        self::printSummary();
    }

    private static function runSuite($name, $class)
    {
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "Suite: $name\n";
        echo str_repeat('=', 60) . "\n";
        
        $class::runAll();
        
        echo "\n";
    }

    private static function printHeader()
    {
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║         SUITE DE PRUEBAS - BACKEND PHP PURO              ║\n";
        echo "║                  CRM Spa Médico                          ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
        echo "PHP Version: " . phpversion() . "\n";
    }

    private static function printSummary()
    {
        $endTime = microtime(true);
        $duration = round($endTime - self::$startTime, 3);
        
        echo "\n";
        echo str_repeat('=', 60) . "\n";
        echo "RESUMEN FINAL\n";
        echo str_repeat('=', 60) . "\n";
        echo "Tiempo de ejecución: {$duration}s\n";
        echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
        echo "\n";
        echo "🎯 Todas las pruebas completadas\n";
        echo "\n";
        echo "Para agregar más pruebas:\n";
        echo "  1. Crea un nuevo archivo *Test.php en tests/\n";
        echo "  2. Agrega la clase con método runAll()\n";
        echo "  3. Inclúyela en run-tests.php\n";
        echo "\n";
    }
}

// Ejecutar pruebas
TestRunner::run();
