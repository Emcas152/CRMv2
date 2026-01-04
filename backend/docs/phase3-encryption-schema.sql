-- ============================================================================
-- PHASE 3.2: ENCRIPTACIÓN DE CAMPOS SENSIBLES - Database Schema
-- ============================================================================
-- Preparar tabla para almacenar campos encriptados
-- Migración de datos existentes (fase posterior)
--
-- Campos a encriptar:
--   1. products.price – Costos de servicios
--   2. patients.email – Email de pacientes
--   3. patients.phone – Teléfono de pacientes
--   4. users.phone – Teléfono (usuarios)
-- ============================================================================

-- ============================================================================
-- 1. ENCRIPTACIÓN DE PRECIOS (products.price)
-- ============================================================================

-- Agregar columnas para almacenar datos encriptados
ALTER TABLE products 
ADD COLUMN IF NOT EXISTS price_encrypted LONGBLOB NULL COMMENT 'Precio encriptado (AES-256-GCM)';

-- Nota: La columna original 'price' se mantendrá hasta que todos los datos estén migrados
-- Luego se renombrará y la encriptada tomará su lugar

-- ============================================================================
-- 2. ENCRIPTACIÓN DE EMAIL Y TELÉFONO (patients)
-- ============================================================================

-- Agregar columnas para almacenar datos encriptados
ALTER TABLE patients 
ADD COLUMN IF NOT EXISTS email_encrypted LONGBLOB NULL COMMENT 'Email encriptado (AES-256-GCM)';

ALTER TABLE patients 
ADD COLUMN IF NOT EXISTS email_hash VARCHAR(64) NULL COMMENT 'SHA256 hash del email para búsqueda sin descifrar';

ALTER TABLE patients 
ADD COLUMN IF NOT EXISTS phone_encrypted LONGBLOB NULL COMMENT 'Teléfono encriptado (AES-256-GCM)';

ALTER TABLE patients 
ADD COLUMN IF NOT EXISTS phone_hash VARCHAR(64) NULL COMMENT 'SHA256 hash del teléfono para búsqueda sin descifrar';

-- Índices para búsquedas exactas
CREATE INDEX IF NOT EXISTS idx_patients_email_hash ON patients(email_hash);
CREATE INDEX IF NOT EXISTS idx_patients_phone_hash ON patients(phone_hash);

-- ============================================================================
-- 3. ENCRIPTACIÓN DE TELÉFONO (users.phone)
-- ============================================================================

-- Agregar columnas para almacenar datos encriptados
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS phone_encrypted LONGBLOB NULL COMMENT 'Teléfono encriptado (AES-256-GCM)';

-- Índice para búsquedas
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS phone_hash VARCHAR(64) NULL COMMENT 'SHA256 hash del teléfono para búsqueda';

CREATE INDEX IF NOT EXISTS idx_users_phone_hash ON users(phone_hash);

-- ============================================================================
-- TABLA DE CONTROL DE MIGRACIÓN
-- ============================================================================

CREATE TABLE IF NOT EXISTS encryption_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    column_name VARCHAR(100) NOT NULL,
    encrypted_column VARCHAR(100) NOT NULL,
    total_records INT DEFAULT 0,
    migrated_records INT DEFAULT 0,
    status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    last_batch_id INT NULL,
    error_message TEXT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    UNIQUE KEY unique_migration (table_name, column_name),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar migraciones planeadas
INSERT IGNORE INTO encryption_migrations (table_name, column_name, encrypted_column, total_records, status)
SELECT 'products', 'price', 'price_encrypted', COUNT(*), 'pending' FROM products
UNION ALL
SELECT 'patients', 'email', 'email_encrypted', COUNT(*), 'pending' FROM patients
UNION ALL
SELECT 'patients', 'phone', 'phone_encrypted', COUNT(*), 'pending' FROM patients
UNION ALL
SELECT 'users', 'phone', 'phone_encrypted', COUNT(*), 'pending' FROM users;

-- ============================================================================
-- VISTA PARA MONITOREO
-- ============================================================================

CREATE OR REPLACE VIEW v_encryption_status AS
SELECT 
    table_name,
    column_name,
    total_records,
    migrated_records,
    CONCAT(ROUND((migrated_records / NULLIF(total_records, 0)) * 100, 2), '%') as progress,
    status,
    completed_at,
    CASE 
        WHEN status = 'completed' THEN '✅'
        WHEN status = 'in_progress' THEN '⏳'
        WHEN status = 'failed' THEN '❌'
        ELSE '⏹️'
    END as icon
FROM encryption_migrations
ORDER BY started_at DESC;

-- ============================================================================
-- CONSULTAS ÚTILES
-- ============================================================================

-- Ver estado de migraciones:
-- SELECT * FROM v_encryption_status;

-- Ver registros que necesitan ser encriptados:
-- SELECT COUNT(*) as unencrypted_products FROM products WHERE price_encrypted IS NULL;
-- SELECT COUNT(*) as unencrypted_patients_email FROM patients WHERE email_encrypted IS NULL;
-- SELECT COUNT(*) as unencrypted_patients_phone FROM patients WHERE phone_encrypted IS NULL;
-- SELECT COUNT(*) as unencrypted_users FROM users WHERE phone_encrypted IS NULL;

-- Ver cambios después de encriptación:
-- SELECT COUNT(*) as encrypted_products FROM products WHERE price_encrypted IS NOT NULL;

