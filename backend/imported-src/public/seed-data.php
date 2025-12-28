<?php
/**
 * Script de Seeders - Datos de prueba
 * USAR SOLO PARA DESARROLLO/DEMO - Eliminar en producción
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    $results = [
        'users_created' => [],
        'patients_created' => 0,
        'products_created' => 0,
        'appointments_created' => 0,
        'sales_created' => 0,
        'errors' => []
    ];
    
    // ==========================================
    // 1. CREAR USUARIOS
    // ==========================================
    
    $users = [
        [
            'name' => 'Super Administrador',
            'email' => 'superadmin@crm.com',
            'password' => password_hash('superadmin123', PASSWORD_BCRYPT),
            'role' => 'superadmin'
        ],
        [
            'name' => 'Administrador',
            'email' => 'admin@crm.com',
            'password' => password_hash('admin123', PASSWORD_BCRYPT),
            'role' => 'admin'
        ],
        [
            'name' => 'Dr. Juan Pérez',
            'email' => 'doctor@crm.com',
            'password' => password_hash('doctor123', PASSWORD_BCRYPT),
            'role' => 'doctor'
        ],
        [
            'name' => 'María González',
            'email' => 'staff@crm.com',
            'password' => password_hash('staff123', PASSWORD_BCRYPT),
            'role' => 'staff'
        ],
        [
            'name' => 'Juan Paciente',
            'email' => 'patient@crm.com',
            'password' => password_hash('patient123', PASSWORD_BCRYPT),
            'role' => 'patient'
        ]
    ];
    
    foreach ($users as $user) {
        // Verificar si ya existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$user['email']]);
        
        if ($stmt->fetch()) {
            $results['users_created'][] = [
                'email' => $user['email'],
                'status' => 'already_exists'
            ];
            continue;
        }
        
        // Crear usuario
        $stmt = $db->prepare("
            INSERT INTO users (name, email, password, role, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $user['name'],
            $user['email'],
            $user['password'],
            $user['role']
        ]);
        
        $results['users_created'][] = [
            'id' => $db->lastInsertId(),
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => 'created'
        ];
    }
    
    // ==========================================
    // 2. CREAR PACIENTES (si la tabla existe)
    // ==========================================
    
    $stmt = $db->query("SHOW TABLES LIKE 'patients'");
    if ($stmt->fetch()) {
        $patients = [
            ['name' => 'Ana López', 'email' => 'ana@example.com', 'phone' => '555-0001'],
            ['name' => 'Carlos Ruiz', 'email' => 'carlos@example.com', 'phone' => '555-0002'],
            ['name' => 'Diana Torres', 'email' => 'diana@example.com', 'phone' => '555-0003'],
            ['name' => 'Eduardo Díaz', 'email' => 'eduardo@example.com', 'phone' => '555-0004'],
            ['name' => 'Fernanda Castro', 'email' => 'fernanda@example.com', 'phone' => '555-0005'],
            ['name' => 'Gabriel Morales', 'email' => 'gabriel@example.com', 'phone' => '555-0006'],
            ['name' => 'Helena Vargas', 'email' => 'helena@example.com', 'phone' => '555-0007'],
            ['name' => 'Ignacio Rojas', 'email' => 'ignacio@example.com', 'phone' => '555-0008'],
            ['name' => 'Julia Mendoza', 'email' => 'julia@example.com', 'phone' => '555-0009'],
            ['name' => 'Kevin Silva', 'email' => 'kevin@example.com', 'phone' => '555-0010']
        ];
        
        foreach ($patients as $patient) {
            // Verificar si ya existe
            $stmt = $db->prepare("SELECT id FROM patients WHERE email = ?");
            $stmt->execute([$patient['email']]);
            
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("
                    INSERT INTO patients (name, email, phone, loyalty_points, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $patient['name'],
                    $patient['email'],
                    $patient['phone'],
                    rand(0, 100)
                ]);
                $results['patients_created']++;
            }
        }
    }
    
    // ==========================================
    // 3. CREAR PRODUCTOS (si la tabla existe)
    // ==========================================
    
    $stmt = $db->query("SHOW TABLES LIKE 'products'");
    if ($stmt->fetch()) {
        $products = [
            // Servicios
            ['name' => 'Limpieza Facial', 'sku' => 'SVC-001', 'type' => 'service', 'price' => 1200.00, 'stock' => 0],
            ['name' => 'Masaje Relajante', 'sku' => 'SVC-002', 'type' => 'service', 'price' => 1500.00, 'stock' => 0],
            ['name' => 'Tratamiento Anti-Edad', 'sku' => 'SVC-003', 'type' => 'service', 'price' => 2500.00, 'stock' => 0],
            ['name' => 'Depilación Láser', 'sku' => 'SVC-004', 'type' => 'service', 'price' => 3000.00, 'stock' => 0],
            ['name' => 'Micropigmentación', 'sku' => 'SVC-005', 'type' => 'service', 'price' => 4000.00, 'stock' => 0],
            
            // Productos
            ['name' => 'Crema Hidratante Premium', 'sku' => 'PRD-001', 'type' => 'product', 'price' => 450.00, 'stock' => 50],
            ['name' => 'Serum Vitamina C', 'sku' => 'PRD-002', 'type' => 'product', 'price' => 650.00, 'stock' => 30],
            ['name' => 'Protector Solar FPS 50', 'sku' => 'PRD-003', 'type' => 'product', 'price' => 380.00, 'stock' => 75],
            ['name' => 'Aceite Esencial de Lavanda', 'sku' => 'PRD-004', 'type' => 'product', 'price' => 280.00, 'stock' => 40],
            ['name' => 'Mascarilla Facial de Colágeno', 'sku' => 'PRD-005', 'type' => 'product', 'price' => 150.00, 'stock' => 100]
        ];
        
        foreach ($products as $product) {
            $stmt = $db->prepare("SELECT id FROM products WHERE sku = ?");
            $stmt->execute([$product['sku']]);
            
            if (!$stmt->fetch()) {
                // Verificar qué columnas existen en la tabla products
                $columns = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
                $hasCost = in_array('cost', $columns);
                $hasLowStockAlert = in_array('low_stock_alert', $columns);
                
                if ($hasCost && $hasLowStockAlert) {
                    $stmt = $db->prepare("
                        INSERT INTO products (name, sku, type, price, cost, stock, low_stock_alert, active, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $product['name'],
                        $product['sku'],
                        $product['type'],
                        $product['price'],
                        $product['price'] * 0.4,
                        $product['stock'],
                        $product['type'] === 'product' ? 10 : 0
                    ]);
                } elseif ($hasCost) {
                    $stmt = $db->prepare("
                        INSERT INTO products (name, sku, type, price, cost, stock, active, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $product['name'],
                        $product['sku'],
                        $product['type'],
                        $product['price'],
                        $product['price'] * 0.4,
                        $product['stock']
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO products (name, sku, type, price, stock, active, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $product['name'],
                        $product['sku'],
                        $product['type'],
                        $product['price'],
                        $product['stock']
                    ]);
                }
                $results['products_created']++;
            }
        }
    }
    
    // ==========================================
    // 4. CREAR CITAS (si la tabla existe)
    // ==========================================
    
    $stmt = $db->query("SHOW TABLES LIKE 'appointments'");
    if ($stmt->fetch()) {
        // Obtener IDs de pacientes
        $patientsIds = $db->query("SELECT id FROM patients LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($patientsIds) > 0) {
            $dates = [
                date('Y-m-d'),
                date('Y-m-d', strtotime('+1 day')),
                date('Y-m-d', strtotime('+2 days')),
                date('Y-m-d', strtotime('+3 days')),
                date('Y-m-d', strtotime('-1 day')),
                date('Y-m-d', strtotime('-2 days'))
            ];
            
            $times = ['09:00:00', '10:30:00', '12:00:00', '14:30:00', '16:00:00', '17:30:00'];
            $services = ['Limpieza Facial', 'Masaje Relajante', 'Tratamiento Anti-Edad', 'Depilación Láser'];
            $statuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            
            for ($i = 0; $i < 15; $i++) {
                $stmt = $db->prepare("
                    INSERT INTO appointments (patient_id, appointment_date, appointment_time, service, status, notes, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $patientsIds[array_rand($patientsIds)],
                    $dates[array_rand($dates)],
                    $times[array_rand($times)],
                    $services[array_rand($services)],
                    $statuses[array_rand($statuses)],
                    'Cita de prueba generada automáticamente'
                ]);
                $results['appointments_created']++;
            }
        }
    }
    
    // ==========================================
    // 5. CREAR VENTAS (si la tabla existe)
    // ==========================================
    
    $stmt = $db->query("SHOW TABLES LIKE 'sales'");
    if ($stmt->fetch()) {
        $patientsIds = $db->query("SELECT id FROM patients LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        $productsData = $db->query("SELECT id, price FROM products LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($patientsIds) > 0 && count($productsData) > 0) {
            $paymentMethods = ['cash', 'card', 'transfer'];
            
            // Verificar si existe la tabla sale_items
            $hasSaleItems = $db->query("SHOW TABLES LIKE 'sale_items'")->fetch();
            
            for ($i = 0; $i < 20; $i++) {
                $product = $productsData[array_rand($productsData)];
                $quantity = rand(1, 3);
                $subtotal = $product['price'] * $quantity;
                $discount = rand(0, 20); // 0-20% descuento
                $total = $subtotal * (1 - $discount / 100);
                
                $stmt = $db->prepare("
                    INSERT INTO sales (patient_id, subtotal, discount, total, payment_method, status, notes, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 'completed', 'Venta de prueba', NOW(), NOW())
                ");
                $stmt->execute([
                    $patientsIds[array_rand($patientsIds)],
                    $subtotal,
                    $discount,
                    $total,
                    $paymentMethods[array_rand($paymentMethods)]
                ]);
                
                $saleId = $db->lastInsertId();
                
                // Crear detalle de venta solo si existe la tabla
                if ($hasSaleItems) {
                    $stmt = $db->prepare("
                        INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $saleId,
                        $product['id'],
                        $quantity,
                        $product['price'],
                        $subtotal
                    ]);
                }
                
                $results['sales_created']++;
            }
        }
    }
    
    // ==========================================
    // RESPUESTA
    // ==========================================
    
    echo json_encode([
        'success' => true,
        'message' => 'Datos de prueba creados exitosamente',
        'summary' => $results,
        'credentials' => [
            'admin' => ['email' => 'admin@crm.com', 'password' => 'admin123'],
            'doctor' => ['email' => 'doctor@crm.com', 'password' => 'doctor123'],
            'staff' => ['email' => 'staff@crm.com', 'password' => 'staff123']
        ],
        'warning' => '⚠️ ELIMINA ESTE ARCHIVO (seed-data.php) DESPUÉS DE USAR'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
