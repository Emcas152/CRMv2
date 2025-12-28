<?php
/**
 * Script para crear la base de datos local y las tablas usando MySQLi
 * Ejecutar: http://localhost/crm/backend/public/setup-local-db.php
 */

header('Content-Type: application/json; charset=utf-8');

// Cargar variables de entorno
require_once __DIR__ . '/../core/helpers.php';

$dbConfig = require __DIR__ . '/../config/database.php';

$results = [
    'success' => false,
    'steps' => [],
    'errors' => []
];

try {
    // PASO 1: Conectar usando MySQLi
    $results['steps'][] = "Conectando a MySQL en {$dbConfig['host']}...";
    
    $mysqli = new mysqli(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        '',
        $dbConfig['port']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Error de conexión: " . $mysqli->connect_error);
    }
    
    $mysqli->set_charset($dbConfig['charset']);
    $results['steps'][] = "✓ Conectado a MySQL exitosamente";

    // PASO 2: Crear base de datos si no existe
    $dbName = $mysqli->real_escape_string($dbConfig['database']);
    $charset = $dbConfig['charset'];
    $collation = $dbConfig['collation'];
    
    $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}` 
            CHARACTER SET {$charset} 
            COLLATE {$collation}";
    
    if (!$mysqli->query($sql)) {
        throw new Exception("Error al crear base de datos: " . $mysqli->error);
    }
    $results['steps'][] = "✓ Base de datos '{$dbName}' creada/verificada";
    
    // PASO 3: Seleccionar la base de datos
    if (!$mysqli->select_db($dbName)) {
        throw new Exception("Error al seleccionar base de datos: " . $mysqli->error);
    }
    $results['steps'][] = "✓ Base de datos seleccionada";

    // PASO 4: Leer y ejecutar el script SQL
    $sqlFile = __DIR__ . '/../install.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo install.sql no encontrado en: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);
    $results['steps'][] = "✓ Archivo install.sql leído";

    // Remover comentarios y líneas vacías
    $lines = explode("\n", $sql);
    $cleanSql = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // Ignorar comentarios y líneas vacías
        if (empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 2) === '/*') {
            continue;
        }
        $cleanSql .= $line . "\n";
    }

    // Ejecutar queries múltiples con multi_query
    if (!$mysqli->multi_query($cleanSql)) {
        throw new Exception("Error ejecutando SQL: " . $mysqli->error);
    }
    
    // Procesar todos los resultados
    $statementsExecuted = 0;
    do {
        $statementsExecuted++;
        // Liberar resultado si existe
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    
    // Verificar si hubo errores
    if ($mysqli->errno) {
        throw new Exception("Error en SQL: " . $mysqli->error);
    }
    
    $results['steps'][] = "✓ Tablas creadas exitosamente ({$statementsExecuted} statements ejecutados)";

    // PASO 5: Verificar tablas creadas
    $tablesResult = $mysqli->query("SHOW TABLES");
    $tables = [];
    while ($row = $tablesResult->fetch_array(MYSQLI_NUM)) {
        $tables[] = $row[0];
    }
    $tablesResult->free();
    
    $results['steps'][] = "✓ Tablas encontradas: " . implode(', ', $tables);
    $results['tables'] = $tables;

    // PASO 6: Insertar usuario admin de prueba si no existe
    $checkStmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $email = 'admin@crm.com';
    $checkStmt->bind_param('s', $email);
    $checkStmt->execute();
    $checkStmt->bind_result($count);
    $checkStmt->fetch();
    $checkStmt->close();
    
    if ($count == 0) {
        $insertStmt = $mysqli->prepare("
            INSERT INTO users (name, email, password, role, phone, active) 
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        
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
    } else {
        $results['steps'][] = "✓ Usuario admin ya existe";
    }

    // PASO 7: Cerrar conexión MySQLi
    $mysqli->close();
    $results['steps'][] = "✓ Conexión MySQLi cerrada correctamente";
    
    $results['success'] = true;
    $results['message'] = "¡Base de datos configurada exitosamente!";
    $results['next_steps'] = [
        "1. Ir a: http://localhost:4200",
        "2. Login con: admin@crm.com / admin123",
        "3. Eliminar este archivo: backend/public/setup-local-db.php"
    ];

} catch (Exception $e) {
    $results['errors'][] = "Error: " . $e->getMessage();
    $results['help'] = [
        "Verifica que MySQL/MariaDB esté corriendo en XAMPP",
        "Verifica las credenciales en .env.local",
        "Asegúrate de que el usuario tenga permisos para crear bases de datos"
    ];
} catch (Exception $e) {
    $results['errors'][] = "Error: " . $e->getMessage();
}

// Mostrar resultado
if ($results['success']) {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
