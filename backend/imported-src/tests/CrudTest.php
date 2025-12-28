<?php
/**
 * Pruebas CRUD (Create, Read, Update, Delete)
 * Prueba operaciones de base de datos en todas las entidades principales
 */

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../config/database.php';

class CrudTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;
    private static $db;
    private static $conn;
    private static $testIds = []; // Almacenar IDs creados para limpieza

    public static function runAll()
    {
        echo "=== Pruebas CRUD ===\n\n";
        
        try {
            self::$db = Database::getInstance();
            self::$conn = self::$db->getConnection();
            echo "✓ Conexión a base de datos establecida\n\n";
        } catch (Exception $e) {
            echo "✗ Error de conexión: " . $e->getMessage() . "\n";
            return;
        }

        // Pruebas de Usuarios
        self::testCreateUser();
        self::testReadUser();
        self::testUpdateUser();
        self::testDeleteUser();

        // Pruebas de Pacientes
        self::testCreatePatient();
        self::testReadPatient();
        self::testUpdatePatient();
        self::testDeletePatient();

        // Pruebas de Productos
        self::testCreateProduct();
        self::testReadProduct();
        self::testUpdateProduct();
        self::testDeleteProduct();

        // Pruebas de Citas
        self::testCreateAppointment();
        self::testReadAppointment();
        self::testUpdateAppointment();
        self::testDeleteAppointment();

        // Limpieza final
        self::cleanup();

        self::printResults();
    }

    // ============================================================
    // PRUEBAS DE USUARIOS
    // ============================================================

    private static function testCreateUser()
    {
        $testName = "Insertar Usuario";
        try {
            $stmt = self::$conn->prepare("
                INSERT INTO users (name, email, password, role, created_at) 
                VALUES (:name, :email, :password, :role, NOW())
            ");
            
            $hashedPassword = password_hash('test123', PASSWORD_BCRYPT);
            
            $uniqueEmail = 'test.crud.' . time() . '@example.com';
            
            $stmt->execute([
                'name' => 'Test User CRUD',
                'email' => $uniqueEmail,
                'password' => $hashedPassword,
                'role' => 'staff'
            ]);
            
            $userId = self::$conn->lastInsertId();
            self::$testIds['user'] = $userId;
            
            if ($userId > 0) {
                self::pass($testName, "Usuario creado con ID: $userId");
            } else {
                self::fail($testName, "No se obtuvo ID del usuario creado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testReadUser()
    {
        $testName = "Leer Usuario";
        try {
            if (!isset(self::$testIds['user'])) {
                self::fail($testName, "No hay ID de usuario para leer");
                return;
            }

            $stmt = self::$conn->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['user']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && strpos($user['email'], 'test.crud.') !== false) {
                self::pass($testName, "Usuario leído correctamente: {$user['name']}");
            } else {
                self::fail($testName, "No se pudo leer el usuario o los datos no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testUpdateUser()
    {
        $testName = "Actualizar Usuario";
        try {
            if (!isset(self::$testIds['user'])) {
                self::fail($testName, "No hay ID de usuario para actualizar");
                return;
            }

            $stmt = self::$conn->prepare("
                UPDATE users 
                SET name = :name, phone = :phone 
                WHERE id = :id
            ");
            
            $stmt->execute([
                'name' => 'Test User CRUD Updated',
                'phone' => '555-9999',
                'id' => self::$testIds['user']
            ]);
            
            // Verificar actualización
            $stmt = self::$conn->prepare("SELECT name, phone FROM users WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['user']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user['name'] === 'Test User CRUD Updated' && $user['phone'] === '555-9999') {
                self::pass($testName, "Usuario actualizado correctamente");
            } else {
                self::fail($testName, "Los datos actualizados no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testDeleteUser()
    {
        $testName = "Eliminar Usuario";
        try {
            if (!isset(self::$testIds['user'])) {
                self::fail($testName, "No hay ID de usuario para eliminar");
                return;
            }

            // Usar exec() directamente para evitar error 1615 con prepared statements
            $id = (int) self::$testIds['user'];
            $affectedRows = self::$conn->exec("DELETE FROM users WHERE id = $id");
            
            // Verificar eliminación
            $stmt = self::$conn->prepare("SELECT COUNT(*) as count FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0 && $affectedRows > 0) {
                self::pass($testName, "Usuario eliminado correctamente");
                unset(self::$testIds['user']);
            } else {
                self::fail($testName, "El usuario no fue eliminado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    // ============================================================
    // PRUEBAS DE PACIENTES
    // ============================================================

    private static function testCreatePatient()
    {
        $testName = "Insertar Paciente";
        try {
            $stmt = self::$conn->prepare("
                INSERT INTO patients (name, email, phone, birthday, address, created_at) 
                VALUES (:name, :email, :phone, :birthday, :address, NOW())
            ");
            
            $uniqueEmail = 'paciente.crud.' . time() . '@example.com';
            
            $stmt->execute([
                'name' => 'Paciente Test CRUD',
                'email' => $uniqueEmail,
                'phone' => '555-1234',
                'birthday' => '1990-05-15',
                'address' => 'Calle Test 123'
            ]);
            
            $patientId = self::$conn->lastInsertId();
            self::$testIds['patient'] = $patientId;
            
            if ($patientId > 0) {
                self::pass($testName, "Paciente creado con ID: $patientId");
            } else {
                self::fail($testName, "No se obtuvo ID del paciente creado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testReadPatient()
    {
        $testName = "Leer Paciente";
        try {
            if (!isset(self::$testIds['patient'])) {
                self::fail($testName, "No hay ID de paciente para leer");
                return;
            }

            $stmt = self::$conn->prepare("SELECT * FROM patients WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['patient']]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($patient && strpos($patient['email'], 'paciente.crud.') !== false) {
                self::pass($testName, "Paciente leído correctamente: {$patient['name']}");
            } else {
                self::fail($testName, "No se pudo leer el paciente");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testUpdatePatient()
    {
        $testName = "Actualizar Paciente";
        try {
            if (!isset(self::$testIds['patient'])) {
                self::fail($testName, "No hay ID de paciente para actualizar");
                return;
            }

            $stmt = self::$conn->prepare("
                UPDATE patients 
                SET phone = :phone, address = :address 
                WHERE id = :id
            ");
            
            $stmt->execute([
                'phone' => '555-UPDATED',
                'address' => 'Calle Actualizada 456',
                'id' => self::$testIds['patient']
            ]);
            
            // Verificar actualización
            $stmt = self::$conn->prepare("SELECT phone, address FROM patients WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['patient']]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($patient['phone'] === '555-UPDATED' && $patient['address'] === 'Calle Actualizada 456') {
                self::pass($testName, "Paciente actualizado correctamente");
            } else {
                self::fail($testName, "Los datos actualizados no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testDeletePatient()
    {
        $testName = "Eliminar Paciente";
        try {
            if (!isset(self::$testIds['patient'])) {
                self::fail($testName, "No hay ID de paciente para eliminar");
                return;
            }

            // Usar exec() directamente para evitar error 1615 con prepared statements
            $id = (int) self::$testIds['patient'];
            $affectedRows = self::$conn->exec("DELETE FROM patients WHERE id = $id");
            
            // Verificar eliminación
            $stmt = self::$conn->prepare("SELECT COUNT(*) as count FROM patients WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0 && $affectedRows > 0) {
                self::pass($testName, "Paciente eliminado correctamente");
                unset(self::$testIds['patient']);
            } else {
                self::fail($testName, "El paciente no fue eliminado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    // ============================================================
    // PRUEBAS DE PRODUCTOS
    // ============================================================

    private static function testCreateProduct()
    {
        $testName = "Insertar Producto";
        try {
            $stmt = self::$conn->prepare("
                INSERT INTO products (name, description, price, stock, type, created_at) 
                VALUES (:name, :description, :price, :stock, :type, NOW())
            ");
            
            $stmt->execute([
                'name' => 'Producto Test CRUD',
                'description' => 'Descripción de prueba',
                'price' => 99.99,
                'stock' => 50,
                'type' => 'product'
            ]);
            
            $productId = self::$conn->lastInsertId();
            self::$testIds['product'] = $productId;
            
            if ($productId > 0) {
                self::pass($testName, "Producto creado con ID: $productId");
            } else {
                self::fail($testName, "No se obtuvo ID del producto creado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testReadProduct()
    {
        $testName = "Leer Producto";
        try {
            if (!isset(self::$testIds['product'])) {
                self::fail($testName, "No hay ID de producto para leer");
                return;
            }

            $stmt = self::$conn->prepare("SELECT * FROM products WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['product']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product && $product['name'] === 'Producto Test CRUD') {
                self::pass($testName, "Producto leído correctamente: {$product['name']} - \${$product['price']}");
            } else {
                self::fail($testName, "No se pudo leer el producto");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testUpdateProduct()
    {
        $testName = "Actualizar Producto";
        try {
            if (!isset(self::$testIds['product'])) {
                self::fail($testName, "No hay ID de producto para actualizar");
                return;
            }

            $stmt = self::$conn->prepare("
                UPDATE products 
                SET price = :price, stock = :stock 
                WHERE id = :id
            ");
            
            $stmt->execute([
                'price' => 149.99,
                'stock' => 75,
                'id' => self::$testIds['product']
            ]);
            
            // Verificar actualización
            $stmt = self::$conn->prepare("SELECT price, stock FROM products WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['product']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product['price'] == 149.99 && $product['stock'] == 75) {
                self::pass($testName, "Producto actualizado: precio \${$product['price']}, stock {$product['stock']}");
            } else {
                self::fail($testName, "Los datos actualizados no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testDeleteProduct()
    {
        $testName = "Eliminar Producto";
        try {
            if (!isset(self::$testIds['product'])) {
                self::fail($testName, "No hay ID de producto para eliminar");
                return;
            }

            // Usar exec() para evitar error 1615
            $id = (int) self::$testIds['product'];
            $affectedRows = self::$conn->exec("DELETE FROM products WHERE id = $id");
            
            // Verificar eliminación
            $stmt = self::$conn->prepare("SELECT COUNT(*) as count FROM products WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0 && $affectedRows > 0) {
                self::pass($testName, "Producto eliminado correctamente");
                unset(self::$testIds['product']);
            } else {
                self::fail($testName, "El producto no fue eliminado");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    // ============================================================
    // PRUEBAS DE CITAS
    // ============================================================

    private static function testCreateAppointment()
    {
        $testName = "Insertar Cita";
        try {
            // Generar email único con microsegundos
            $uniqueEmail = 'temp.appt.' . microtime(true) . '@test.com';
            
            // Verificar si ya existe un paciente con este email (no debería)
            $stmt = self::$conn->prepare("SELECT id FROM patients WHERE email = :email");
            $stmt->execute(['email' => $uniqueEmail]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $tempPatientId = $existing['id'];
            } else {
                // Crear un paciente temporal para la cita
                $stmt = self::$conn->prepare("
                    INSERT INTO patients (name, email, phone, created_at) 
                    VALUES ('Paciente Temporal Cita', :email, '555-0000', NOW())
                ");
                $stmt->execute(['email' => $uniqueEmail]);
                $tempPatientId = self::$conn->lastInsertId();
            }
            
            self::$testIds['temp_patient'] = $tempPatientId;

            // Crear la cita
            $stmt = self::$conn->prepare("
                INSERT INTO appointments (patient_id, appointment_date, appointment_time, service, status, created_at) 
                VALUES (:patient_id, :date, :time, :service, :status, NOW())
            ");
            
            $stmt->execute([
                'patient_id' => $tempPatientId,
                'date' => '2025-12-15',
                'time' => '10:00:00',
                'service' => 'Consulta de prueba CRUD',
                'status' => 'pending'
            ]);
            
            $appointmentId = self::$conn->lastInsertId();
            self::$testIds['appointment'] = $appointmentId;
            
            if ($appointmentId > 0) {
                self::pass($testName, "Cita creada con ID: $appointmentId (Paciente: $tempPatientId)");
            } else {
                self::fail($testName, "No se obtuvo ID de la cita creada");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testReadAppointment()
    {
        $testName = "Leer Cita";
        try {
            if (!isset(self::$testIds['appointment'])) {
                self::fail($testName, "No hay ID de cita para leer");
                return;
            }

            $stmt = self::$conn->prepare("SELECT * FROM appointments WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['appointment']]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($appointment && $appointment['status'] === 'pending') {
                self::pass($testName, "Cita leída correctamente: {$appointment['appointment_date']} {$appointment['appointment_time']}");
            } else {
                self::fail($testName, "No se pudo leer la cita");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testUpdateAppointment()
    {
        $testName = "Actualizar Cita";
        try {
            if (!isset(self::$testIds['appointment'])) {
                self::fail($testName, "No hay ID de cita para actualizar");
                return;
            }

            $stmt = self::$conn->prepare("
                UPDATE appointments 
                SET status = :status, appointment_time = :time 
                WHERE id = :id
            ");
            
            $stmt->execute([
                'status' => 'confirmed',
                'time' => '14:30:00',
                'id' => self::$testIds['appointment']
            ]);
            
            // Verificar actualización
            $stmt = self::$conn->prepare("SELECT status, appointment_time FROM appointments WHERE id = :id");
            $stmt->execute(['id' => self::$testIds['appointment']]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($appointment['status'] === 'confirmed' && $appointment['appointment_time'] === '14:30:00') {
                self::pass($testName, "Cita actualizada: status {$appointment['status']}, hora {$appointment['appointment_time']}");
            } else {
                self::fail($testName, "Los datos actualizados no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testDeleteAppointment()
    {
        $testName = "Eliminar Cita";
        try {
            if (!isset(self::$testIds['appointment'])) {
                self::fail($testName, "No hay ID de cita para eliminar");
                return;
            }

            // Usar exec() para evitar error 1615
            $id = (int) self::$testIds['appointment'];
            $affectedRows = self::$conn->exec("DELETE FROM appointments WHERE id = $id");
            
            // Verificar eliminación
            $stmt = self::$conn->prepare("SELECT COUNT(*) as count FROM appointments WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0 && $affectedRows > 0) {
                self::pass($testName, "Cita eliminada correctamente");
                
                // Limpiar también el paciente temporal
                if (isset(self::$testIds['temp_patient'])) {
                    $tempId = (int) self::$testIds['temp_patient'];
                    self::$conn->exec("DELETE FROM patients WHERE id = $tempId");
                    unset(self::$testIds['temp_patient']);
                }
                
                unset(self::$testIds['appointment']);
            } else {
                self::fail($testName, "La cita no fue eliminada");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    // ============================================================
    // FUNCIONES AUXILIARES
    // ============================================================

    private static function cleanup()
    {
        // Limpiar cualquier registro de prueba que haya quedado
        try {
            if (isset(self::$testIds['appointment'])) {
                self::$conn->prepare("DELETE FROM appointments WHERE id = :id")->execute(['id' => self::$testIds['appointment']]);
            }
            if (isset(self::$testIds['temp_patient'])) {
                self::$conn->prepare("DELETE FROM patients WHERE id = :id")->execute(['id' => self::$testIds['temp_patient']]);
            }
            if (isset(self::$testIds['product'])) {
                self::$conn->prepare("DELETE FROM products WHERE id = :id")->execute(['id' => self::$testIds['product']]);
            }
            if (isset(self::$testIds['patient'])) {
                self::$conn->prepare("DELETE FROM patients WHERE id = :id")->execute(['id' => self::$testIds['patient']]);
            }
            if (isset(self::$testIds['user'])) {
                self::$conn->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => self::$testIds['user']]);
            }
        } catch (Exception $e) {
            // Ignorar errores de limpieza
        }
    }

    private static function pass($testName, $message = '')
    {
        self::$testsPassed++;
        echo "✅ PASS: $testName\n";
        if ($message) echo "   $message\n";
    }

    private static function fail($testName, $message = '')
    {
        self::$testsFailed++;
        echo "❌ FAIL: $testName\n";
        if ($message) echo "   $message\n";
    }

    private static function printResults()
    {
        echo "\n=== Resultados ===\n";
        $total = self::$testsPassed + self::$testsFailed;
        echo "Pruebas ejecutadas: $total\n";
        echo "✅ Pasadas: " . self::$testsPassed . "\n";
        echo "❌ Fallidas: " . self::$testsFailed . "\n";
        if ($total > 0) {
            echo "Tasa de éxito: " . round((self::$testsPassed / $total) * 100, 2) . "%\n\n";
        }
    }
}
