# Phase 3.2: Encriptación de Campos Sensibles

**Estado**: ✅ SCHEMA APLICADO - LISTO PARA MIGRACIÓN

## Descripción General

Phase 3.2 implementa encriptación de campos sensibles usando AES-256-GCM con autenticación HMAC-SHA256. Los datos encriptados se almacenan en columnas LONGBLOB mientras se mantienen las columnas originales para compatibilidad durante la migración.

## Campos Encriptados

| Tabla | Campo | Encriptado | Hash | Razón |
|-------|-------|-----------|------|-------|
| `products` | `price` | `price_encrypted` | ❌ | Costo de servicios |
| `patients` | `email` | `email_encrypted` | `email_hash` | PII - Contacto |
| `patients` | `phone` | `phone_encrypted` | `phone_hash` | PII - Contacto |
| `users` | `phone` | `phone_encrypted` | `phone_hash` | PII - Contacto |

## Arquitectura Técnica

### 1. Clase Crypto (Existente - core/Crypto.php)

Proporciona funciones criptográficas de bajo nivel:
- `encryptBytes($data)` → String encriptado con AES-256-GCM
- `decryptBytes($encrypted)` → String desencriptado
- `isEncryptedPayload($data)` → Valida formato encriptado
- `encryptField($value)` → Encripta valores de campo
- `decryptField($encrypted)` → Desencripta valores
- `hashField($value)` → SHA256 hash para búsqueda sin descifrar

### 2. Clase FieldEncryption (Nueva - core/FieldEncryption.php)

Wrapper de alto nivel para encriptación de campos específicos:
- `encryptValue($value)` → Encripta usando Crypto
- `decryptValue($encrypted)` → Desencripta usando Crypto
- `hashValue($value)` → Genera hash SHA256
- `verifyHash($value, $hash)` → Verifica hash
- `encryptFieldWithHash($value, $type)` → Encripta + hash en transacción
- `getEncryptedColumn($type)` → Nombre columna encriptada
- `getHashColumn($type)` → Nombre columna hash
- `logMigration($db, ...)` → Registra progreso migración

### 3. Tablas de Control

#### `encryption_migrations` (Tracking)
Rastrea el progreso de cada migración:
```sql
CREATE TABLE encryption_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100),        -- 'products', 'patients', etc
    column_name VARCHAR(100),       -- 'price', 'email', etc
    encrypted_column VARCHAR(100),  -- 'price_encrypted', etc
    total_records INT,              -- Total a migrar
    migrated_records INT,           -- Ya migrados
    status ENUM(...),               -- pending, in_progress, completed, failed
    error_message TEXT,             -- Si hay error
    started_at TIMESTAMP,
    completed_at TIMESTAMP
)
```

#### `v_encryption_status` (Vista de Monitoreo)
Consulta actualizada para mostrar progreso:
```sql
SELECT 
    table_name,
    column_name,
    progress,           -- Porcentaje completado
    status,
    icon               -- ✅⏳❌⏹️
FROM v_encryption_status
```

## Cómo Usar

### 1. Encriptación de Nuevo Dato

Al insertar un nuevo registro con campo sensible:

```php
use App\Core\FieldEncryption;

// Método 1: Encriptar + hash en una línea
$encrypted_data = FieldEncryption::encryptFieldWithHash('value', FieldEncryption::TYPE_EMAIL);
// Retorna: ['email_encrypted' => 'valor_encriptado', 'email_hash' => 'hash_valor']

// Insertar
$db->insert('patients', [
    'name' => 'John',
    'email_encrypted' => $encrypted_data['email_encrypted'],
    'email_hash' => $encrypted_data['email_hash']
]);

// Método 2: Paso a paso
$encrypted = FieldEncryption::encryptValue($email);
$hash = FieldEncryption::hashValue($email);
$db->update('patients', [
    'email_encrypted' => $encrypted,
    'email_hash' => $hash
], ['id' => $id]);
```

### 2. Desencriptar Datos

Al leer datos encriptados:

```php
use App\Core\FieldEncryption;

$patient = $db->find('patients', $id);
$email = FieldEncryption::decryptValue($patient['email_encrypted']);
```

### 3. Buscar sin Descifrar

Usando hashes para búsqueda eficiente:

```php
use App\Core\FieldEncryption;

// Buscar paciente por email sin descifrar
$hash = FieldEncryption::hashValue('john@example.com');
$patient = $db->findOne('patients', [
    'email_hash' => $hash
]);
```

### 4. Ejecutar Migración de Datos Existentes

```bash
# Desde backend/
php tools/migrate-encrypt-fields.php

# Salida:
# ╔════════════════════════════════════════════════════════════════╗
# ║       MIGRATION: Encryptación de Campos Sensibles             ║
# ╚════════════════════════════════════════════════════════════════╝
# 
# → Migrando products.price
#   Procesando 150 registros...
#   ▓ Progreso: 150/150 (100%)
#   ✅ Completado: 150 registros encriptados
# 
# → Migrando patients.email
#   Procesando 45 registros...
#   ▓ Progreso: 45/45 (100%)
#   ✅ Completado: 45 registros encriptados
# 
# ╔════════════════════════════════════════════════════════════════╗
# ║                     Estado Final                              ║
# ╚════════════════════════════════════════════════════════════════╝
# ✅ products       | price           | 100%  | completed
# ✅ patients       | email           | 100%  | completed
# ✅ patients       | phone           | 100%  | completed
# ✅ users          | phone           | 100%  | completed
```

### 5. Monitorear Progreso

```sql
-- Ver estado en tiempo real
SELECT * FROM v_encryption_status;

-- Contar registros sin encriptar por tabla
SELECT COUNT(*) as pending FROM products WHERE price_encrypted IS NULL;
SELECT COUNT(*) as pending FROM patients WHERE email_encrypted IS NULL;
SELECT COUNT(*) as pending FROM patients WHERE phone_encrypted IS NULL;
SELECT COUNT(*) as pending FROM users WHERE phone_encrypted IS NULL;

-- Ver últimos errores
SELECT table_name, column_name, error_message 
FROM encryption_migrations 
WHERE status = 'failed';
```

## Integración en Controllers

### ProductsController - Inserción

```php
public function store(Request $request)
{
    // Validar
    $validated = $request->validate([
        'name' => 'required',
        'price' => 'required|numeric'
    ]);
    
    // Encriptar precio
    $encryptedPrice = FieldEncryption::encryptFieldWithHash(
        $validated['price'],
        FieldEncryption::TYPE_PRICE
    );
    
    // Guardar
    Product::create([
        'name' => $validated['name'],
        'price' => $validated['price'], // mantener para referencia
        'price_encrypted' => $encryptedPrice['price_encrypted']
    ]);
}

// GET - Lectura
public function show(Product $product)
{
    $decrypted = FieldEncryption::decryptValue($product->price_encrypted);
    return response()->json([
        'price' => $decrypted
    ]);
}
```

### PatientsController - Encriptación Email

```php
public function update(Request $request, Patient $patient)
{
    $validated = $request->validate([
        'email' => 'email|unique:patients,email'
    ]);
    
    if (isset($validated['email'])) {
        $encrypted = FieldEncryption::encryptFieldWithHash(
            $validated['email'],
            FieldEncryption::TYPE_EMAIL
        );
        
        $validated['email_encrypted'] = $encrypted['email_encrypted'];
        $validated['email_hash'] = $encrypted['email_hash'];
        unset($validated['email']); // no guardar en claro
    }
    
    $patient->update($validated);
}

// Búsqueda por email
public function findByEmail($email)
{
    $hash = FieldEncryption::hashValue($email);
    return Patient::where('email_hash', $hash)->first();
}
```

## Seguridad

### ✅ Implementado
- AES-256-GCM encriptación con autenticación
- HMAC-SHA256 para integridad
- Hashes para búsqueda sin descifrar
- Logs de auditoría en audit_logs
- Migración con tracking progresivo

### ⚠️ Consideraciones
- La clave de encriptación se deriva del `APP_SECRET` en `.env`
- Cambiar `APP_SECRET` invalida datos encriptados (no recomendado)
- Los hashes NO se pueden revertir a valores originales
- Comparaciones sensibles a mayúsculas en búsquedas por hash

### 🔐 Procedimiento de Respaldo Antes de Migrar
```bash
# 1. Respaldar BD completa
mysqldump -u root crm_spa_medico > backup-pre-encryption-$(date +%Y%m%d-%H%M%S).sql

# 2. Respaldar aplicación
cp -r . ../backup-pre-encryption-$(date +%Y%m%d-%H%M%S)/

# 3. Ejecutar migración
php backend/tools/migrate-encrypt-fields.php

# 4. Verificar
SELECT * FROM v_encryption_status;

# 5. Si hay error, restaurar
mysql -u root crm_spa_medico < backup-pre-encryption-*.sql
```

## Testing

### Test de Encriptación/Desencriptación
```php
// backend/tests/EncryptionTest.php
<?php

use App\Core\FieldEncryption;
use PHPUnit\Framework\TestCase;

class EncryptionTest extends TestCase
{
    public function test_encrypt_decrypt_roundtrip()
    {
        $value = '12345678-9';
        
        $encrypted = FieldEncryption::encryptValue($value);
        $decrypted = FieldEncryption::decryptValue($encrypted);
        
        $this->assertEquals($value, $decrypted);
    }
    
    public function test_hash_consistency()
    {
        $value = 'test@example.com';
        
        $hash1 = FieldEncryption::hashValue($value);
        $hash2 = FieldEncryption::hashValue($value);
        
        $this->assertEquals($hash1, $hash2);
    }
    
    public function test_verify_hash()
    {
        $value = '3001234567';
        $hash = FieldEncryption::hashValue($value);
        
        $this->assertTrue(FieldEncryption::verifyHash($value, $hash));
        $this->assertFalse(FieldEncryption::verifyHash('wrong', $hash));
    }
}
```

### Test de Migración
```bash
# Verificar que la migración completó correctamente
php -r "
\$output = shell_exec('php backend/tools/migrate-encrypt-fields.php');
echo \$output;
\$success = (strpos(\$output, '✅') !== false && strpos(\$output, 'completed') !== false);
exit(\$success ? 0 : 1);
"
```

## Próximos Pasos

1. ✅ Schema aplicado a BD
2. ✅ Clases FieldEncryption creadas
3. ⏳ Ejecutar `php backend/tools/migrate-encrypt-fields.php`
4. ⏳ Verificar `SELECT * FROM v_encryption_status`
5. ⏳ Actualizar Controllers para usar FieldEncryption
6. ⏳ Agregar validación de campos encriptados
7. ⏳ Implementar búsquedas con hash
8. ⏳ Documentar en API (OpenAPI/Swagger)

## Phase 3.3 (Siguiendo)

Alertas automáticas para eventos críticos:
- Bulk delete detection
- Role change auditing
- 2FA failure tracking
- Rate limit alerts
