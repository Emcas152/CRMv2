<?php
/**
 * Pruebas para la clase Sanitizer
 */

require_once __DIR__ . '/../core/Sanitizer.php';

class SanitizerTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;

    public static function runAll()
    {
        echo "=== Pruebas de Sanitizer ===\n\n";
        
        self::testSanitizeString();
        self::testSanitizeEmail();
        self::testSanitizeHtml();
        self::testSanitizeXSS();
        self::testSanitizeArrayInput();
        self::testSanitizeNestedArray();
        
        self::printResults();
    }

    private static function testSanitizeString()
    {
        $testName = "Sanitizar String Básico";
        try {
            $input = "  Hello World  ";
            $expected = "Hello World";
            $result = Sanitizer::clean($input);
            
            if ($result === $expected) {
                self::pass($testName);
            } else {
                self::fail($testName, "Esperado: '$expected', Obtenido: '$result'");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizeEmail()
    {
        $testName = "Sanitizar Email";
        try {
            $input = "  Test@EXAMPLE.com  ";
            $result = Sanitizer::email($input);
            
            // filter_var no convierte a lowercase automáticamente
            if (strtolower($result) === 'test@example.com') {
                self::pass($testName);
            } else {
                self::fail($testName, "Esperado: 'test@example.com', Obtenido: '$result'");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizeHtml()
    {
        $testName = "Sanitizar HTML Tags";
        try {
            $input = "<script>alert('XSS')</script>Hello<b>World</b>";
            $result = Sanitizer::clean($input);
            
            // No debe contener tags HTML
            if (strpos($result, '<script>') === false && strpos($result, '<b>') === false) {
                self::pass($testName);
            } else {
                self::fail($testName, "HTML tags no fueron removidos: '$result'");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizeXSS()
    {
        $testName = "Prevenir XSS";
        try {
            $xssAttempts = [
                "<script>alert('XSS')</script>",
                "<img src=x onerror=alert('XSS')>",
                "javascript:alert('XSS')",
                "<iframe src='javascript:alert(1)'></iframe>"
            ];
            
            $allSafe = true;
            foreach ($xssAttempts as $attempt) {
                $result = Sanitizer::clean($attempt);
                // Verificar que no contiene caracteres HTML ejecutables: < >
                // El Sanitizer convierte estos a entidades HTML (&lt; &gt;)
                if (strpos($result, '<') !== false || strpos($result, '>') !== false) {
                    $allSafe = false;
                    break;
                }
            }
            
            if ($allSafe) {
                self::pass($testName);
            } else {
                self::fail($testName, "XSS no fue completamente sanitizado");
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizeArrayInput()
    {
        $testName = "Sanitizar Array de Input";
        try {
            $input = [
                'name' => '  John Doe  ',
                'email' => '  test@example.com  ',
                'description' => '<script>alert(1)</script>Safe text',
                'age' => '25'
            ];
            
            $result = Sanitizer::input($input);
            
            if ($result['name'] === 'John Doe' &&
                strpos($result['description'], '<script>') === false &&
                $result['age'] === '25') {
                self::pass($testName);
            } else {
                self::fail($testName, "Array no sanitizado correctamente: " . json_encode($result));
            }
        } catch (Exception $e) {
            self::fail($testName, $e->getMessage());
        }
    }

    private static function testSanitizeNestedArray()
    {
        $testName = "Sanitizar Array Anidado";
        try {
            $input = [
                'user' => [
                    'name' => '  Admin  ',
                    'email' => '  admin@test.com  '
                ],
                'metadata' => [
                    'tags' => ['  tag1  ', '  tag2  ']
                ]
            ];
            
            $result = Sanitizer::input($input);
            
            if ($result['user']['name'] === 'Admin' &&
                $result['metadata']['tags'][0] === 'tag1' &&
                $result['metadata']['tags'][1] === 'tag2') {
                self::pass($testName);
            } else {
                self::fail($testName, "Array anidado no sanitizado correctamente: " . json_encode($result));
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
