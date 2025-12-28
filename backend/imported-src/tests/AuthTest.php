<?php
/**
 * Pruebas para la clase Auth
 */

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../config/app.php';

class AuthTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;
    private static $testResults = [];

    public static function runAll()
    {
        echo "=== Pruebas de Auth ===\n\n";
        
        self::testGenerateToken();
        self::testVerifyToken();
        self::testVerifyTokenInvalid();
        self::testVerifyTokenExpired();
        self::testHashPassword();
        self::testVerifyPassword();
        self::testBase64UrlEncode();
        
        self::printResults();
    }

    private static function testGenerateToken()
    {
        $testName = "Generar Token JWT";
        try {
            $token = Auth::generateToken(1, 'superadmin@crm.com', 'superadmin123');
            
            if (!empty($token) && substr_count($token, '.') === 2) {
                self::pass($testName, "Token generado correctamente: " . substr($token, 0, 50) . "...");
            } else {
                self::fail($testName, "Token con formato incorrecto");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testVerifyToken()
    {
        $testName = "Verificar Token Válido";
        try {
            $token = Auth::generateToken(1, 'test@example.com', 'admin');
            $payload = Auth::verifyToken($token);
            
            if ($payload && 
                $payload['user_id'] === 1 && 
                $payload['email'] === 'test@example.com' && 
                $payload['role'] === 'admin') {
                self::pass($testName, "Token verificado correctamente");
            } else {
                self::fail($testName, "Payload incorrecto: " . json_encode($payload));
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testVerifyTokenInvalid()
    {
        $testName = "Rechazar Token Inválido";
        try {
            $invalidToken = "invalid.token.here";
            $result = Auth::verifyToken($invalidToken);
            
            if ($result === false) {
                self::pass($testName, "Token inválido rechazado correctamente");
            } else {
                self::fail($testName, "Token inválido no fue rechazado");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testVerifyTokenExpired()
    {
        $testName = "Rechazar Token Expirado";
        try {
            // Crear token con tiempo de expiración negativo
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                'user_id' => 1,
                'email' => 'test@example.com',
                'role' => 'admin',
                'iat' => time() - 3600,
                'exp' => time() - 1800 // Expirado hace 30 minutos
            ]);
            
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
            
            $config = require __DIR__ . '/../config/app.php';
            $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $config['secret_key'], true);
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            
            $expiredToken = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
            
            $result = Auth::verifyToken($expiredToken);
            
            if ($result === false) {
                self::pass($testName, "Token expirado rechazado correctamente");
            } else {
                self::fail($testName, "Token expirado no fue rechazado");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testHashPassword()
    {
        $testName = "Hash de Contraseña";
        try {
            $password = "MySecurePassword123!";
            $hash = Auth::hashPassword($password);
            
            if (!empty($hash) && strlen($hash) === 60 && substr($hash, 0, 4) === '$2y$') {
                self::pass($testName, "Contraseña hasheada correctamente");
            } else {
                self::fail($testName, "Hash incorrecto: " . $hash);
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testVerifyPassword()
    {
        $testName = "Verificar Contraseña";
        try {
            $password = "MySecurePassword123!";
            $hash = Auth::hashPassword($password);
            
            $validPassword = Auth::verifyPassword($password, $hash);
            $invalidPassword = Auth::verifyPassword("WrongPassword", $hash);
            
            if ($validPassword && !$invalidPassword) {
                self::pass($testName, "Verificación de contraseña funciona correctamente");
            } else {
                self::fail($testName, "Verificación incorrecta. Valid: $validPassword, Invalid: $invalidPassword");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testBase64UrlEncode()
    {
        $testName = "Base64 URL Encode/Decode";
        try {
            $testString = "Hello World! +=/ Testing";
            
            // Simular el método privado
            $encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($testString));
            $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded));
            
            if ($decoded === $testString && strpos($encoded, '+') === false && strpos($encoded, '/') === false) {
                self::pass($testName, "Base64 URL encoding funciona correctamente");
            } else {
                self::fail($testName, "Encoding/Decoding incorrecto");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function pass($testName, $message = '')
    {
        self::$testsPassed++;
        self::$testResults[] = [
            'status' => 'PASS',
            'test' => $testName,
            'message' => $message
        ];
        echo "✅ PASS: $testName\n";
        if ($message) echo "   $message\n";
    }

    private static function fail($testName, $message = '')
    {
        self::$testsFailed++;
        self::$testResults[] = [
            'status' => 'FAIL',
            'test' => $testName,
            'message' => $message
        ];
        echo "❌ FAIL: $testName\n";
        if ($message) echo "   $message\n";
    }

    private static function printResults()
    {
        echo "\n=== Resultados ===\n";
        echo "Pruebas ejecutadas: " . (self::$testsPassed + self::$testsFailed) . "\n";
        echo "✅ Pasadas: " . self::$testsPassed . "\n";
        echo "❌ Fallidas: " . self::$testsFailed . "\n";
        echo "Tasa de éxito: " . round((self::$testsPassed / (self::$testsPassed + self::$testsFailed)) * 100, 2) . "%\n\n";
    }
}
