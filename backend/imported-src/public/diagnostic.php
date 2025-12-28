<?php
/**
 * Diagnóstico de endpoints - Pacientes y Productos
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

$results = [
    'pacientes' => [],
    'productos' => []
];

try {
    $db = Database::getInstance();
    
    // Verificar tabla patients
    $patients = $db->fetchAll('SELECT * FROM patients ORDER BY id DESC LIMIT 5');
    $results['pacientes']['total'] = count($db->fetchAll('SELECT id FROM patients'));
    $results['pacientes']['ultimos_5'] = $patients;
    
    // Verificar tabla products
    $products = $db->fetchAll('SELECT * FROM products ORDER BY id DESC LIMIT 5');
    $results['productos']['total'] = count($db->fetchAll('SELECT id FROM products'));
    $results['productos']['ultimos_5'] = $products;
    
    $results['success'] = true;
    
} catch (Exception $e) {
    $results['error'] = $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
