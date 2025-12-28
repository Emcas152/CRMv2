<?php
/**
 * Ejecutar SOLO las pruebas CRUD
 * Para pruebas locales sin depender de MySQL
 */

require_once __DIR__ . '/../core/helpers.php';

// Configuración de zona horaria
date_default_timezone_set('America/Mexico_City');

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         PRUEBAS CRUD - BACKEND PHP PURO                  ║\n";
echo "║              CRM Spa Médico                              ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . phpversion() . "\n\n";

// Verificar conexión a base de datos
try {
    require_once __DIR__ . '/../core/Database.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    if (!$conn) {
        echo "❌ ERROR: No se pudo conectar a la base de datos\n";
        echo "Verifica que MySQL esté corriendo y las credenciales sean correctas.\n\n";
        exit(1);
    }
    
    echo "✓ Conexión a base de datos establecida\n\n";
    
    // Cargar y ejecutar pruebas CRUD
    require_once __DIR__ . '/CrudTest.php';
    
    CrudTest::run();
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    exit(1);
}
