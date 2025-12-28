<?php
/**
 * Pruebas para la clase Validator
 */

require_once __DIR__ . '/../core/Validator.php';

class ValidatorTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;

    public static function runAll()
    {
        echo "=== Pruebas de Validator ===\n\n";
        
        self::testValidateRequired();
        self::testValidateEmail();
        self::testValidateMinLength();
        self::testValidateMaxLength();
        self::testValidateNumeric();
        self::testValidatePhone();
        self::testValidateDate();
        self::testCompleteValidation();
        
        self::printResults();
    }

    private static function testValidateRequired()
    {
        $testName = "Validar Campo Requerido";
        try {
            // Caso válido
            $data = ['name' => 'John Doe', 'email' => 'test@example.com'];
            $rules = ['name' => 'required', 'email' => 'required'];
            
            $validator = new Validator($data, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                // Ahora probar caso inválido
                $dataInvalid = ['name' => 'John Doe', 'email' => ''];
                $validatorInvalid = new Validator($dataInvalid, $rules);
                
                try {
                    $validatorInvalid->validate();
                    self::fail($testName, "No detectó campo requerido vacío");
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'email') !== false) {
                        self::pass($testName);
                    } else {
                        self::fail($testName, "Error incorrecto: " . $e->getMessage());
                    }
                }
            } else {
                self::fail($testName, "Datos válidos fueron rechazados");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidateEmail()
    {
        $testName = "Validar Email";
        try {
            $validEmail = ['email' => 'test@example.com'];
            $rules = ['email' => 'email'];
            
            $validator = new Validator($validEmail, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                // Probar email inválido
                $invalidEmail = ['email' => 'not-an-email'];
                $validatorInvalid = new Validator($invalidEmail, $rules);
                
                try {
                    $validatorInvalid->validate();
                    self::fail($testName, "No detectó email inválido");
                } catch (Exception $e) {
                    self::pass($testName);
                }
            } else {
                self::fail($testName, "Email válido fue rechazado");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidateMinLength()
    {
        $testName = "Validar Longitud Mínima";
        try {
            $data = ['password' => 'short'];
            $rules = ['password' => 'min:8'];
            
            $validator = new Validator($data, $rules);
            
            try {
                $validator->validate();
                self::fail($testName, "No detectó contraseña corta");
            } catch (Exception $e) {
                self::pass($testName);
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidateMaxLength()
    {
        $testName = "Validar Longitud Máxima";
        try {
            $data = ['name' => str_repeat('a', 300)];
            $rules = ['name' => 'max:255'];
            
            $validator = new Validator($data, $rules);
            
            try {
                $validator->validate();
                self::fail($testName, "No detectó nombre muy largo");
            } catch (Exception $e) {
                self::pass($testName);
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidateNumeric()
    {
        $testName = "Validar Numérico";
        try {
            $validData = ['age' => '25'];
            $rules = ['age' => 'numeric'];
            
            $validator = new Validator($validData, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                // Probar inválido
                $invalidData = ['age' => 'twenty-five'];
                $validatorInvalid = new Validator($invalidData, $rules);
                
                try {
                    $validatorInvalid->validate();
                    self::fail($testName, "No detectó valor no numérico");
                } catch (Exception $e) {
                    self::pass($testName);
                }
            } else {
                self::fail($testName, "Validación numérica incorrecta");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidatePhone()
    {
        $testName = "Validar Teléfono con Regex";
        try {
            // Patrón simple: solo dígitos (después de validar, eliminaremos caracteres especiales)
            // Acepta 9-15 dígitos con cualquier combinación de espacios, guiones, paréntesis, +
            $phonePattern = '/^[+\d\s\-\(\)]{9,20}$/';
            
            $validPhones = [
                ['phone' => '+34600123456'],
                ['phone' => '600123456'],
                ['phone' => '5551234567']
            ];
            
            $rules = ['phone' => "regex:{$phonePattern}"];
            
            $allValid = true;
            foreach ($validPhones as $data) {
                $validator = new Validator($data, $rules);
                try {
                    $validator->validate();
                } catch (Exception $e) {
                    $allValid = false;
                    break;
                }
            }
            
            if ($allValid) {
                // Probar inválido (letras no permitidas)
                $invalidPhone = ['phone' => 'not-a-phone'];
                $validatorInvalid = new Validator($invalidPhone, $rules);
                
                try {
                    $validatorInvalid->validate();
                    self::fail($testName, "No detectó teléfono inválido");
                } catch (Exception $e) {
                    self::pass($testName);
                }
            } else {
                self::fail($testName, "Teléfonos válidos fueron rechazados");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testValidateDate()
    {
        $testName = "Validar Fecha";
        try {
            $validDate = ['birthday' => '1990-05-15'];
            $rules = ['birthday' => 'date'];
            
            $validator = new Validator($validDate, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                // Probar inválido
                $invalidDate = ['birthday' => '2025-13-45'];
                $validatorInvalid = new Validator($invalidDate, $rules);
                
                try {
                    $validatorInvalid->validate();
                    self::fail($testName, "No detectó fecha inválida");
                } catch (Exception $e) {
                    self::pass($testName);
                }
            } else {
                self::fail($testName, "Validación de fecha incorrecta");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testCompleteValidation()
    {
        $testName = "Validación Completa de Registro";
        try {
            $data = [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'SecurePass123!',
                'phone' => '+34 600 123 456',
                'age' => '30'
            ];
            
            $rules = [
                'name' => 'required|min:3|max:255',
                'email' => 'required|email',
                'password' => 'required|min:8',
                'phone' => 'required|phone',
                'age' => 'required|numeric'
            ];
            
            $validator = new Validator($data, $rules);
            $result = $validator->validate();
            
            if ($result === true) {
                self::pass($testName);
            } else {
                self::fail($testName, "Datos válidos generaron errores");
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
