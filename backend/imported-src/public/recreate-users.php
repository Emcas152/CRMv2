<?php
/**
 * Script para recrear la tabla users con las columnas correctas
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/helpers.php';
$dbConfig = require __DIR__ . '/../config/database.php';

$results = [
    'success' => false,
    'steps' => []
];

try {
    $mysqli = new mysqli(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['database'],
        $dbConfig['port']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Error de conexión: " . $mysqli->connect_error);
    }
    
    $mysqli->set_charset($dbConfig['charset']);
    $results['steps'][] = "✓ Conectado a MySQL";

    // Eliminar tabla users
    $mysqli->query("DROP TABLE IF EXISTS users");
    $results['steps'][] = "✓ Tabla users eliminada";

    // Recrear tabla users con columnas correctas
    $createTable = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('superadmin', 'admin', 'doctor', 'staff', 'patient') DEFAULT 'patient',
        phone VARCHAR(20),
        active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if (!$mysqli->query($createTable)) {
        throw new Exception("Error al crear tabla users: " . $mysqli->error);
    }
    $results['steps'][] = "✓ Tabla users recreada con columnas phone y active";

    // Insertar usuario superadmin
    $insertStmt = $mysqli->prepare("
        INSERT INTO users (name, email, password, role, phone, active) 
        VALUES (?, ?, ?, ?, ?, 1)
    ");

    // Superadmin
    $name = 'Super Administrador';
    $email = 'superadmin@crm.com';
    $password = password_hash('superadmin123', PASSWORD_BCRYPT);
    $role = 'superadmin';
    $phone = '5550000000';
    $insertStmt->bind_param('sssss', $name, $email, $password, $role, $phone);
    if (!$insertStmt->execute()) {
        throw new Exception("Error al crear usuario superadmin: " . $insertStmt->error);
    }
    $results['steps'][] = "✓ Usuario superadmin creado: superadmin@crm.com / superadmin123";

    // Admin
    $name = 'Administrador';
    $email = 'admin@crm.com';
    $password = password_hash('admin123', PASSWORD_BCRYPT);
    $role = 'admin';
    $phone = '5551234567';
    $insertStmt->bind_param('sssss', $name, $email, $password, $role, $phone);
    if (!$insertStmt->execute()) {
        throw new Exception("Error al crear usuario admin: " . $insertStmt->error);
    }
    $insertStmt->close();
    $results['steps'][] = "✓ Usuario admin creado: admin@crm.com / admin123";
    
    $mysqli->close();
    
    $results['success'] = true;
    $results['message'] = "¡Tabla users recreada exitosamente!";
    $results['next_steps'] = [
        "1. Ya puedes hacer login en: http://localhost:4200",
        "2. Credenciales: admin@crm.com / admin123",
        "3. Elimina estos archivos de seguridad después de probar"
    ];

} catch (Exception $e) {
    $results['errors'][] = "Error: " . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
