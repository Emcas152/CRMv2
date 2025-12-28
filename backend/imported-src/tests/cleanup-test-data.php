<?php
/**
 * Limpiar Datos de Pruebas
 * Ejecutar antes de las pruebas CRUD para limpiar registros anteriores
 */

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

echo "🧹 Limpiando datos de pruebas anteriores...\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Eliminar usuarios de prueba
    $stmt = $conn->prepare("DELETE FROM users WHERE email LIKE 'test.crud%'");
    $stmt->execute();
    $deletedUsers = $stmt->rowCount();
    echo "✓ Usuarios de prueba eliminados: $deletedUsers\n";
    
    // Eliminar pacientes de prueba (incluyendo temporales)
    $stmt = $conn->prepare("DELETE FROM patients WHERE email LIKE 'paciente.crud%' OR email LIKE 'temp.appointment%'");
    $stmt->execute();
    $deletedPatients = $stmt->rowCount();
    echo "✓ Pacientes de prueba eliminados: $deletedPatients\n";
    
    // Eliminar productos de prueba
    $stmt = $conn->prepare("DELETE FROM products WHERE name LIKE '%Test CRUD%'");
    $stmt->execute();
    $deletedProducts = $stmt->rowCount();
    echo "✓ Productos de prueba eliminados: $deletedProducts\n";
    
    // Las citas se eliminan automáticamente si los pacientes se eliminan (si hay FK CASCADE)
    // Si no, eliminarlas manualmente
    $stmt = $conn->prepare("DELETE FROM appointments WHERE service LIKE '%prueba CRUD%'");
    $stmt->execute();
    $deletedAppointments = $stmt->rowCount();
    echo "✓ Citas de prueba eliminadas: $deletedAppointments\n";
    
    echo "\n✅ Limpieza completada\n";
    echo "Total eliminados: " . ($deletedUsers + $deletedPatients + $deletedProducts + $deletedAppointments) . " registros\n";
    
} catch (Exception $e) {
    echo "❌ Error al limpiar: " . $e->getMessage() . "\n";
    exit(1);
}
