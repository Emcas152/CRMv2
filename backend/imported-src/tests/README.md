# 🧪 Suite de Pruebas - Backend PHP Puro

Sistema completo de pruebas para el backend del CRM Spa Médico.

## 📋 Índice

- [Descripción](#descripción)
- [Tipos de Pruebas](#tipos-de-pruebas)
- [Ejecución](#ejecución)
- [Estructura](#estructura)
- [Agregar Nuevas Pruebas](#agregar-nuevas-pruebas)

## 🎯 Descripción

Este directorio contiene pruebas unitarias e integración para validar el funcionamiento correcto del backend PHP puro. Las pruebas cubren:

- **Autenticación JWT**
- **Validación de datos**
- **Sanitización de entrada**
- **Integración de API**
- **Conexión a base de datos**

## 📊 Tipos de Pruebas

### 1. Pruebas Unitarias

#### AuthTest.php
Prueba la clase `Auth` que maneja:
- ✅ Generación de tokens JWT
- ✅ Verificación de tokens válidos
- ✅ Rechazo de tokens inválidos
- ✅ Rechazo de tokens expirados
- ✅ Hash de contraseñas (bcrypt)
- ✅ Verificación de contraseñas
- ✅ Codificación Base64 URL

#### ValidatorTest.php
Prueba la clase `Validator` que valida:
- ✅ Campos requeridos
- ✅ Formato de email
- ✅ Longitud mínima y máxima
- ✅ Valores numéricos
- ✅ Números de teléfono
- ✅ Fechas
- ✅ Validaciones combinadas

#### SanitizerTest.php
Prueba la clase `Sanitizer` que sanitiza:
- ✅ Strings básicos (trim, espacios)
- ✅ Emails (lowercase, trim)
- ✅ Remoción de HTML tags
- ✅ Prevención de XSS
- ✅ Arrays de input
- ✅ Arrays anidados

### 2. Pruebas de Integración

#### ApiIntegrationTest.php
Prueba la integración completa:
- ✅ Flujo de autenticación completo
- ✅ Generación de múltiples tokens
- ✅ Flujo de validación de entrada
- ✅ Flujo de sanitización de entrada
- ✅ Conexión y consultas a base de datos

### 3. Pruebas CRUD

#### CrudTest.php
Prueba operaciones de base de datos en entidades principales:

**Usuarios (4 pruebas)**
- ✅ Insertar usuario con password hash
- ✅ Leer usuario por ID
- ✅ Actualizar nombre y teléfono
- ✅ Eliminar usuario y verificar

**Pacientes (4 pruebas)**
- ✅ Insertar paciente completo
- ✅ Leer paciente con todos sus datos
- ✅ Actualizar teléfono y dirección
- ✅ Eliminar paciente

**Productos (4 pruebas)**
- ✅ Insertar producto con precio y stock
- ✅ Leer producto por ID
- ✅ Actualizar precio y stock
- ✅ Eliminar producto

**Citas (4 pruebas)**
- ✅ Insertar cita con paciente temporal
- ✅ Leer cita con fecha y hora
- ✅ Actualizar status y hora
- ✅ Eliminar cita y paciente temporal

## 🚀 Ejecución

### Ejecutar Todas las Pruebas

```bash
php tests/run-tests.php
```

### Ejecutar Pruebas Individuales

```bash
# Solo pruebas de Auth
php -r "require 'tests/AuthTest.php'; AuthTest::runAll();"

# Solo pruebas de Validator
php -r "require 'tests/ValidatorTest.php'; ValidatorTest::runAll();"

# Solo pruebas de Sanitizer
php -r "require 'tests/SanitizerTest.php'; SanitizerTest::runAll();"

# Solo pruebas de Integración
php -r "require 'tests/ApiIntegrationTest.php'; ApiIntegrationTest::runAll();"
```

### Desde XAMPP

1. Abre la terminal en el directorio del backend:
```bash
cd C:\xampp\htdocs\crm\backend
```

2. Ejecuta las pruebas:
```bash
php tests\run-tests.php
```

## 📁 Estructura

```
tests/
├── README.md                 # Este archivo
├── run-tests.php            # Runner principal
├── AuthTest.php             # Pruebas de autenticación
├── ValidatorTest.php        # Pruebas de validación
├── SanitizerTest.php        # Pruebas de sanitización
└── ApiIntegrationTest.php   # Pruebas de integración
```

## ✨ Salida Esperada

```
╔══════════════════════════════════════════════════════════╗
║         SUITE DE PRUEBAS - BACKEND PHP PURO              ║
║                  CRM Spa Médico                          ║
╚══════════════════════════════════════════════════════════╝

Fecha: 2025-12-10 14:30:00
PHP Version: 8.2.12

============================================================
Suite: Auth
============================================================

=== Pruebas de Auth ===

✅ PASS: Generar Token JWT
   Token generado correctamente: eyJ0eXAiOiJKV1QiLCJhbGc...
✅ PASS: Verificar Token Válido
   Token verificado correctamente
✅ PASS: Rechazar Token Inválido
   Token inválido rechazado correctamente
✅ PASS: Rechazar Token Expirado
   Token expirado rechazado correctamente
✅ PASS: Hash de Contraseña
   Contraseña hasheada correctamente
✅ PASS: Verificar Contraseña
   Verificación de contraseña funciona correctamente
✅ PASS: Base64 URL Encode/Decode
   Base64 URL encoding funciona correctamente

=== Resultados ===
Pruebas ejecutadas: 7
✅ Pasadas: 7
❌ Fallidas: 0
Tasa de éxito: 100.00%

[... más suites ...]

============================================================
RESUMEN FINAL
============================================================
Tiempo de ejecución: 0.245s
Fecha: 2025-12-10 14:30:00

🎯 Todas las pruebas completadas
```

## 📝 Agregar Nuevas Pruebas

### Paso 1: Crear Archivo de Prueba

Crea un nuevo archivo `MiClaseTest.php`:

```php
<?php
require_once __DIR__ . '/../core/MiClase.php';

class MiClaseTest
{
    private static $testsPassed = 0;
    private static $testsFailed = 0;

    public static function runAll()
    {
        echo "=== Pruebas de MiClase ===\n\n";
        
        self::testMetodo1();
        self::testMetodo2();
        
        self::printResults();
    }

    private static function testMetodo1()
    {
        $testName = "Descripción del Test";
        try {
            // Tu código de prueba aquí
            $result = MiClase::metodo1();
            
            if ($result === 'esperado') {
                self::pass($testName);
            } else {
                self::fail($testName, "Resultado incorrecto");
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
        $total = self::$testsPassed + self::$testsFailed;
        echo "Pruebas ejecutadas: $total\n";
        echo "✅ Pasadas: " . self::$testsPassed . "\n";
        echo "❌ Fallidas: " . self::$testsFailed . "\n";
        if ($total > 0) {
            echo "Tasa de éxito: " . round((self::$testsPassed / $total) * 100, 2) . "%\n\n";
        }
    }
}
```

### Paso 2: Registrar en run-tests.php

Agrega tu suite al runner:

```php
require_once __DIR__ . '/MiClaseTest.php';

// En el método run():
self::runSuite('MiClase', 'MiClaseTest');
```

## 🔧 Requisitos

- PHP 7.4 o superior
- Extensiones PHP requeridas:
  - PDO
  - PDO_MySQL
  - JSON
  - OpenSSL (para JWT)
- Base de datos MySQL configurada (para pruebas de integración)

## ⚙️ Configuración

Las pruebas usan la configuración del archivo `.env` en la raíz del proyecto:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=crm_db
DB_USER=root
DB_PASS=
SECRET_KEY=tu_clave_secreta_aqui
```

## 📊 Cobertura

| Componente | Cobertura | Tests |
|------------|-----------|-------|
| Auth       | ✅ 100%   | 7     |
| Validator  | ✅ 100%   | 8     |
| Sanitizer  | ✅ 100%   | 6     |
| Integration| ✅ 100%   | 5     |
| **Total**  | **100%**  | **26**|

## 🐛 Troubleshooting

### Error: "Call to undefined function"
Verifica que todos los `require_once` estén correctos y que las clases existan.

### Error: "Connection refused"
Verifica que MySQL esté corriendo y que la configuración en `.env` sea correcta.

### Error: "Class not found"
Asegúrate de ejecutar desde el directorio raíz del backend.

## 📚 Recursos

- [PHPUnit Documentation](https://phpunit.de/)
- [JWT Specification](https://jwt.io/)
- [OWASP Security Testing](https://owasp.org/www-project-web-security-testing-guide/)

## 🤝 Contribuir

Para agregar más pruebas:

1. Crea un archivo `*Test.php`
2. Implementa el método estático `runAll()`
3. Usa `pass()` y `fail()` para reportar resultados
4. Registra la suite en `run-tests.php`

---

**Última actualización:** Diciembre 2025  
**Versión:** 1.0.0
