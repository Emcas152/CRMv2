-- ============================================================================
-- PHASE 2: TWO-FACTOR AUTHENTICATION (2FA) - Database Schema
-- ============================================================================
-- Tabla para gestionar configuración de 2FA por usuario y códigos temporales
--
-- Features:
--   - Enable/disable 2FA por usuario
--   - Códigos de 6 dígitos válidos por 5 minutos
--   - Envío por email
--   - Tracking de intentos de verificación
--   - Backup codes para recuperación
-- ============================================================================

-- Agregar columnas para 2FA a tabla users (idempotente)
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) DEFAULT 0 COMMENT '1=2FA habilitado, 0=deshabilitado',
ADD COLUMN IF NOT EXISTS two_factor_method ENUM('email', 'sms', 'whatsapp') DEFAULT 'email' COMMENT 'Método de 2FA preferido',
ADD COLUMN IF NOT EXISTS two_factor_secret VARCHAR(255) NULL COMMENT 'Secret para TOTP (futuro)',
ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL COMMENT 'Número de teléfono para SMS/WhatsApp';

-- Índice para acelerar búsquedas de usuarios con 2FA
DROP INDEX IF EXISTS idx_two_factor_enabled ON users;
CREATE INDEX idx_two_factor_enabled ON users(two_factor_enabled);

-- Tabla para códigos 2FA temporales
CREATE TABLE IF NOT EXISTS two_factor_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(10) NOT NULL COMMENT 'Código de 6 dígitos',
    method ENUM('email', 'sms', 'whatsapp') NOT NULL DEFAULT 'email',
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    verified TINYINT(1) DEFAULT 0 COMMENT '1=verificado, 0=pendiente',
    verified_at TIMESTAMP NULL,
    expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Código válido por 5 minutos',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_code (code),
    INDEX idx_expires (expires_at),
    INDEX idx_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para backup codes (códigos de recuperación)
CREATE TABLE IF NOT EXISTS two_factor_backup_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(20) NOT NULL COMMENT 'Código de recuperación (8-10 caracteres)',
    used TINYINT(1) DEFAULT 0 COMMENT '1=usado, 0=disponible',
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_code (code),
    INDEX idx_user_id (user_id),
    INDEX idx_used (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Procedimiento para limpiar códigos expirados
-- ============================================================================
DROP EVENT IF EXISTS cleanup_2fa_codes_every_10min;
DROP PROCEDURE IF EXISTS cleanup_expired_2fa_codes;

DELIMITER //

CREATE PROCEDURE cleanup_expired_2fa_codes()
BEGIN
        -- Eliminar códigos expirados (> 15 minutos)
        DELETE FROM two_factor_codes 
        WHERE expires_at < NOW()
            AND verified = 0;
      
        -- Eliminar códigos verificados antiguos (> 7 días)
        DELETE FROM two_factor_codes
        WHERE verified = 1
            AND verified_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
END//

DELIMITER ;

DELIMITER //
CREATE EVENT cleanup_2fa_codes_every_10min
ON SCHEDULE EVERY 10 MINUTE
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_expired_2fa_codes();//
DELIMITER ;

-- ============================================================================
-- Procedimiento para generar backup codes
-- ============================================================================
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS generate_backup_codes(IN p_user_id INT, IN p_count INT)
BEGIN
    DECLARE i INT DEFAULT 0;
    DECLARE random_code VARCHAR(20);
    
    -- Eliminar códigos antiguos no usados
    DELETE FROM two_factor_backup_codes 
    WHERE user_id = p_user_id AND used = 0;
    
    -- Generar nuevos códigos
    WHILE i < p_count DO
        SET random_code = CONCAT(
            LPAD(FLOOR(RAND() * 10000), 4, '0'),
            '-',
            LPAD(FLOOR(RAND() * 10000), 4, '0')
        );
        
        INSERT IGNORE INTO two_factor_backup_codes (user_id, code)
        VALUES (p_user_id, random_code);
        
        SET i = i + 1;
    END WHILE;
END//

DELIMITER ;

-- ============================================================================
-- Índices adicionales para consultas rápidas
-- ============================================================================

-- Buscar códigos activos por usuario
CREATE INDEX idx_user_active_codes ON two_factor_codes(user_id, expires_at, verified);

-- Buscar backup codes disponibles
CREATE INDEX idx_backup_available ON two_factor_backup_codes(user_id, used);

-- ============================================================================
-- Consultas útiles para monitoreo
-- ============================================================================

-- Ver usuarios con 2FA habilitado:
-- SELECT id, email, two_factor_enabled, two_factor_method
-- FROM users
-- WHERE two_factor_enabled = 1;

-- Ver códigos pendientes de verificación:
-- SELECT u.email, t.code, t.created_at, t.expires_at,
--        TIMESTAMPDIFF(SECOND, NOW(), t.expires_at) as seconds_remaining
-- FROM two_factor_codes t
-- JOIN users u ON t.user_id = u.id
-- WHERE t.verified = 0 AND t.expires_at > NOW()
-- ORDER BY t.created_at DESC;

-- Ver intentos de verificación fallidos:
-- SELECT u.email, COUNT(*) as attempts, MAX(t.created_at) as last_attempt
-- FROM two_factor_codes t
-- JOIN users u ON t.user_id = u.id
-- WHERE t.verified = 0 AND t.expires_at < NOW()
-- GROUP BY u.email
-- HAVING attempts >= 3
-- ORDER BY attempts DESC;

-- Ver backup codes disponibles por usuario:
-- SELECT u.email, COUNT(*) as available_codes
-- FROM two_factor_backup_codes b
-- JOIN users u ON b.user_id = u.id
-- WHERE b.used = 0
-- GROUP BY u.email;
