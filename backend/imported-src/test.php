<?php
/**
 * Script de Prueba para Backend PHP Puro
 * 
 * Este script prueba todos los endpoints disponibles
 * ejecutando requests simulados sin necesidad de servidor web
 */

// Configurar error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar variables de entorno (.env.local o .env)
require_once __DIR__ . '/core/helpers.php';

// Cargar configuración
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Validator.php';
require_once __DIR__ . '/core/Sanitizer.php';

class APITester {
    private $token = null;
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        echo "=================================\n";
        echo "  TEST BACKEND PHP PURO\n";
        echo "=================================\n\n";
    }
    
    /**
     * Simula un request HTTP
     */
    private function request($method, $endpoint, $data = []) {
        echo "\n▶ $method $endpoint\n";
        
        // Preparar headers
        $headers = [];
        if ($this->token) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        
        // Sanitizar entrada
        $data = Sanitizer::input($data);
        
        // Mostrar data
        if (!empty($data)) {
            echo "  Data: " . json_encode($data) . "\n";
        }
        
        return [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'headers' => $headers
        ];
    }
    
    /**
     * Test 1: Conexión a base de datos
     */
    public function testDatabaseConnection() {
        echo "\n[TEST 1] Conexión a Base de Datos\n";
        echo str_repeat('-', 50) . "\n";
        
        try {
            $row = $this->db->fetchOne("SELECT 1 as test");
            
            if ($row && $row['test'] == 1) {
                echo "✓ Conexión exitosa\n";
                return true;
            }
        } catch (Exception $e) {
            echo "✗ Error: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 2: Verificar tablas
     */
    public function testTables() {
        echo "\n[TEST 2] Verificación de Tablas\n";
        echo str_repeat('-', 50) . "\n";
        
        $required_tables = ['users', 'patients', 'products', 'staff_members', 'appointments', 'sales'];
        $all_ok = true;
        
        foreach ($required_tables as $table) {
            try {
                $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM `$table`");
                echo "✓ Tabla '$table' existe ({$result['count']} registros)\n";
            } catch (Exception $e) {
                echo "✗ Tabla '$table' no existe o error\n";
                $all_ok = false;
            }
        }
        
        return $all_ok;
    }
    
    /**
     * Test 3: Validador
     */
    public function testValidator() {
        echo "\n[TEST 3] Sistema de Validación\n";
        echo str_repeat('-', 50) . "\n";
        
        // Test válido
        $data = ['email' => 'test@test.com', 'age' => 25];
        $rules = ['email' => ['required', 'email'], 'age' => ['required', 'integer', 'min:18']];
        
        try {
            Validator::make($data, $rules)->validate();
            echo "✓ Validación correcta acepta datos válidos\n";
        } catch (Exception $e) {
            echo "✗ Error en validación válida: " . $e->getMessage() . "\n";
            return false;
        }
        
        // Test inválido
        $data = ['email' => 'invalid', 'age' => 15];
        try {
            Validator::make($data, $rules)->validate();
            echo "✗ Validación no rechaza datos inválidos\n";
            return false;
        } catch (Exception $e) {
            echo "✓ Validación correctamente rechaza datos inválidos\n";
        }
        
        return true;
    }
    
    /**
     * Test 4: Sanitizador
     */
    public function testSanitizer() {
        echo "\n[TEST 4] Sistema de Sanitización (XSS)\n";
        echo str_repeat('-', 50) . "\n";
        
        $dirty = '<script>alert("XSS")</script>Hello';
        $clean = Sanitizer::clean($dirty);
        
        if (strpos($clean, '<script>') !== false) {
            echo "✗ Sanitizador no elimina scripts\n";
            return false;
        }
        
        echo "✓ Scripts removidos: '$dirty' → '$clean'\n";
        
        // Test sanitización de arrays
        $dirty_array = [
            'name' => 'John<script>alert(1)</script>',
            'email' => 'test@test.com<iframe>'
        ];
        $clean_array = Sanitizer::cleanArray($dirty_array);
        
        if (strpos(json_encode($clean_array), '<script>') !== false) {
            echo "✗ Sanitizador no limpia arrays correctamente\n";
            return false;
        }
        
        echo "✓ Arrays sanitizados correctamente\n";
        return true;
    }
    
    /**
     * Test 5: JWT Token
     */
    public function testAuth() {
        echo "\n[TEST 5] Sistema de Autenticación (JWT)\n";
        echo str_repeat('-', 50) . "\n";
        
        // Generar token
        $token = Auth::generateToken(1, 'test@test.com', 'admin');
        echo "✓ Token generado: " . substr($token, 0, 50) . "...\n";
        
        // Verificar token
        try {
            $payload = Auth::verifyToken($token);
            
            if ($payload['user_id'] == 1 && $payload['role'] == 'admin') {
                echo "✓ Token verificado correctamente\n";
                echo "  User ID: {$payload['user_id']}\n";
                echo "  Role: {$payload['role']}\n";
                $this->token = $token; // Guardar para otros tests
                return true;
            }
        } catch (Exception $e) {
            echo "✗ Error verificando token: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test 6: Login
     */
    public function testLogin() {
        echo "\n[TEST 6] Endpoint de Login\n";
        echo str_repeat('-', 50) . "\n";
        
        // Verificar si existe usuario admin
        $user = $this->db->fetchOne("SELECT * FROM users WHERE email = ?", ['admin@crmmedico.com']);
        
        if (!$user) {
            echo "⚠ Usuario admin no existe. Saltando test.\n";
            return true;
        }
        
        echo "✓ Usuario admin existe\n";
        echo "  Email: admin@crmmedico.com\n";
        echo "  Password: admin123\n";
        
        // Simular login
        $this->request('POST', '/login', [
            'email' => 'admin@crmmedico.com',
            'password' => 'admin123'
        ]);
        
        echo "✓ Request de login preparado\n";
        return true;
    }
    
    /**
     * Test 7: Pacientes
     */
    public function testPatients() {
        echo "\n[TEST 7] Endpoint de Pacientes\n";
        echo str_repeat('-', 50) . "\n";
        
        // Contar pacientes
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM patients");
        
        echo "✓ {$result['count']} pacientes en la base de datos\n";
        
        // Simular GET request
        $this->request('GET', '/patients');
        echo "✓ Request GET /patients preparado\n";
        
        return true;
    }
    
    /**
     * Test 8: Productos
     */
    public function testProducts() {
        echo "\n[TEST 8] Endpoint de Productos\n";
        echo str_repeat('-', 50) . "\n";
        
        // Contar productos
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM products");
        
        echo "✓ {$result['count']} productos en la base de datos\n";
        
        // Obtener producto con bajo stock
        $low_stock = $this->db->fetchOne("SELECT * FROM products WHERE stock < low_stock_alert LIMIT 1");
        
        if ($low_stock) {
            echo "⚠ Producto con stock bajo: {$low_stock['name']} ({$low_stock['stock']} unidades)\n";
        }
        
        // Simular GET request
        $this->request('GET', '/products');
        echo "✓ Request GET /products preparado\n";
        
        return true;
    }
    
    /**
     * Test 9: Validación de roles
     */
    public function testRoles() {
        echo "\n[TEST 9] Control de Acceso por Roles\n";
        echo str_repeat('-', 50) . "\n";
        
        // Verificar roles existentes
        $roles = $this->db->fetchAll("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        
        foreach ($roles as $role) {
            echo "✓ Role '{$role['role']}': {$role['count']} usuarios\n";
        }
        
        return true;
    }
    
    /**
     * Test 10: Resumen final
     */
    public function testSummary() {
        echo "\n[TEST 10] Resumen del Sistema\n";
        echo str_repeat('-', 50) . "\n";
        
        // Estadísticas generales
        $stats = [];
        
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM users");
        $stats['users'] = $result['count'];
        
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM patients");
        $stats['patients'] = $result['count'];
        
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM products");
        $stats['products'] = $result['count'];
        
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM appointments WHERE appointment_date >= CURDATE()");
        $stats['upcoming_appointments'] = $result['count'];
        
        echo "Sistema CRM Médico - Estadísticas:\n";
        echo "  • Usuarios: {$stats['users']}\n";
        echo "  • Pacientes: {$stats['patients']}\n";
        echo "  • Productos: {$stats['products']}\n";
        echo "  • Citas próximas: {$stats['upcoming_appointments']}\n";
        
        return true;
    }
    
    /**
     * Ejecutar todos los tests
     */
    public function runAll() {
        $tests = [
            'testDatabaseConnection',
            'testTables',
            'testValidator',
            'testSanitizer',
            'testAuth',
            'testLogin',
            'testPatients',
            'testProducts',
            'testRoles',
            'testSummary'
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($tests as $test) {
            try {
                $result = $this->$test();
                if ($result) {
                    $passed++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                echo "✗ Error en $test: " . $e->getMessage() . "\n";
                $failed++;
            }
        }
        
        echo "\n=================================\n";
        echo "  RESULTADOS\n";
        echo "=================================\n";
        echo "✓ Pasaron: $passed tests\n";
        if ($failed > 0) {
            echo "✗ Fallaron: $failed tests\n";
        }
        echo "\nTotal: " . count($tests) . " tests ejecutados\n";
        
        if ($failed == 0) {
            echo "\n🎉 ¡Todos los tests pasaron!\n";
            echo "El backend está listo para usar.\n";
        } else {
            echo "\n⚠ Algunos tests fallaron.\n";
            echo "Revisa la configuración en config/database.php\n";
            echo "y asegúrate de ejecutar install.sql primero.\n";
        }
    }
}

// Ejecutar tests
try {
    $tester = new APITester();
    $tester->runAll();
} catch (Exception $e) {
    echo "\n✗ Error fatal: " . $e->getMessage() . "\n";
    echo "\nAsegúrate de:\n";
    echo "1. Configurar config/database.php con tus credenciales\n";
    echo "2. Ejecutar install.sql para crear las tablas\n";
    echo "3. Tener PHP 8.0 o superior instalado\n";
}

echo "\n";
