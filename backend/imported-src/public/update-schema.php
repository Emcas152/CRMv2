<?php
/**
 * Actualizar estructura de base de datos
 * Ejecutar UNA SOLA VEZ
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    $updates = [];
    
    // Verificar y agregar columnas en sales
    $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'subtotal'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE sales ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER patient_id");
        $updates[] = "Added column 'subtotal' to sales";
    }
    
    $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'discount'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE sales ADD COLUMN discount DECIMAL(10,2) DEFAULT 0 AFTER subtotal");
        $updates[] = "Added column 'discount' to sales";
    }
    
    $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'payment_method'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE sales ADD COLUMN payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash' AFTER total");
        $updates[] = "Added column 'payment_method' to sales";
    }
    
    $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'notes'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE sales ADD COLUMN notes TEXT AFTER payment_method");
        $updates[] = "Added column 'notes' to sales";
    }
    
    // Verificar índices
    $stmt = $db->query("SHOW INDEX FROM sales WHERE Key_name = 'idx_status'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE sales ADD INDEX idx_status (status)");
        $updates[] = "Added index 'idx_status' to sales";
    }
    
    // Actualizar enum de appointments
    try {
        $db->exec("ALTER TABLE appointments MODIFY COLUMN status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'scheduled') DEFAULT 'pending'");
        $updates[] = "Updated status enum in appointments";
    } catch (Exception $e) {
        // Ya existe, no pasa nada
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Estructura actualizada exitosamente',
        'updates' => $updates,
        'warning' => '⚠️ ELIMINA ESTE ARCHIVO (update-schema.php) DESPUÉS DE EJECUTAR'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
