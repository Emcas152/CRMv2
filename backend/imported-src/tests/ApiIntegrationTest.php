<?php
/**
 * Pruebas de Integración de API
 * Prueba los endpoints sin hacer peticiones HTTP reales
 */

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../core/Sanitizer.php';

class ApiIntegrationTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;
    private static $db;

    public static function runAll()
    {
        echo "=== Pruebas de Integración de API ===\n\n";
        
        // Inicializar base de datos
        try {
            self::$db = Database::getInstance();
            echo "✓ Conexión a base de datos establecida\n\n";
        } catch (Exception $e) {
            echo "❌ Error al conectar con la base de datos: " . $e->getMessage() . "\n";
            return;
        }
        
        self::testAuthFlow();
        self::testTokenGeneration();
        self::testValidationFlow();
        self::testSanitizationFlow();
        self::testDatabaseConnection();
        
        self::printResults();
    }

    private static function testAuthFlow()
    {
        $testName = "Flujo de Autenticación Completo";
        try {
            // Simular registro de usuario
            $userData = [
                'user_id' => 999,
                'email' => 'integration@test.com',
                'role' => 'admin'
            ];
            
            // Generar token
            $token = Auth::generateToken($userData['user_id'], $userData['email'], $userData['role']);
            
            if (!$token) {
                self::fail($testName, "No se pudo generar token");
                return;
            }
            
            // Verificar token
            $payload = Auth::verifyToken($token);
            
            if (!$payload) {
                self::fail($testName, "No se pudo verificar token");
                return;
            }
            
            // Validar payload
            if ($payload['user_id'] === $userData['user_id'] &&
                $payload['email'] === $userData['email'] &&
                $payload['role'] === $userData['role']) {
                self::pass($testName);
            } else {
                self::fail($testName, "Datos del payload no coinciden");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testTokenGeneration()
    {
        $testName = "Generación de Múltiples Tokens";
        try {
            $tokens = [];
            
            // Generar varios tokens
            for ($i = 1; $i <= 5; $i++) {
                $token = Auth::generateToken($i, "user{$i}@test.com", 'admin');
                $tokens[] = $token;
            }
            
            // Verificar que todos son únicos
            $uniqueTokens = array_unique($tokens);
            
            if (count($uniqueTokens) === count($tokens)) {
                self::pass($testName, "5 tokens únicos generados");
            } else {
                self::fail($testName, "Tokens duplicados encontrados");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidationFlow()
    {
        $testName = "Flujo de Validación de Entrada";
        try {
            // Simular datos de registro
            $input = [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'SecurePass123!',
                'phone' => '+34 600 123 456'
            ];
            
            $rules = [
                'name' => 'required|min:3|max:255',
                'email' => 'required|email',
                'password' => 'required|min:8',
                'phone' => 'required|phone'
            ];
            
            // Validar
            $validator = new Validator($input, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                self::pass($testName);
            } else {
                self::fail($testName, "Validación falló");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizationFlow()
    {
        $testName = "Flujo de Sanitización de Entrada";
        try {
            // Simular entrada maliciosa
            $maliciousInput = [
                'name' => '<script>alert("XSS")</script>John Doe',
                'email' => '  malicious@example.com  ',
                'description' => 'Normal text <img src=x onerror=alert(1)>'
            ];
            
            // Sanitizar
            $clean = Sanitizer::input($maliciousInput);
            
            // Verificar que está limpio
            $isSafe = strpos($clean['name'], '<script>') === false &&
                     strpos($clean['description'], '<img') === false;
            
            if ($isSafe) {
                self::pass($testName);
            } else {
                self::fail($testName, "Sanitización incompleta: " . json_encode($clean));
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testDatabaseConnection()
    {
        $testName = "Conexión y Consulta a Base de Datos";
        try {
            // Obtener el objeto PDO
            $pdo = self::$db->getConnection();
            
            // Probar una consulta simple
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (isset($result['count']) && is_numeric($result['count'])) {
                self::pass($testName, "Base de datos contiene {$result['count']} usuarios");
            } else {
                self::fail($testName, "Consulta no retornó resultados esperados");
            }
            
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
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
        echo "Pruebas ejecutadas: " . (self::$testsPassed + self::$testsFailed) . "\n";
        echo "✅ Pasadas: " . self::$testsPassed . "\n";
        echo "❌ Fallidas: " . self::$testsFailed . "\n";
        if (self::$testsPassed + self::$testsFailed > 0) {
            echo "Tasa de éxito: " . round((self::$testsPassed / (self::$testsPassed + self::$testsFailed)) * 100, 2) . "%\n\n";
        }
    }
}
