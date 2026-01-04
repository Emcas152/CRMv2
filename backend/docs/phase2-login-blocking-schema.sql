-- ============================================================================
-- PHASE 2: LOGIN ATTEMPT BLOCKING - Database Schema
-- ============================================================================
-- Esta tabla registra todos los intentos de login (exitosos y fallidos)
-- para implementar bloqueo automático después de 5 intentos fallidos.
--
-- Features:
--   - Tracking de intentos por email + IP
--   - Bloqueo temporal (15 minutos)
--   - Auto-limpieza de registros antiguos (> 24 horas)
--   - Auditoría de patrones de ataque
-- ============================================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv4 o IPv6',
    user_agent TEXT COMMENT 'Browser/client info',
    success TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=exitoso, 0=fallido',
    failure_reason VARCHAR(100) COMMENT 'invalid_password, user_not_found, account_locked',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_ip (ip_address),
    INDEX idx_created (created_at),
    INDEX idx_email_created (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabla para bloqueos activos (opcional, se puede calcular on-the-fly)
-- ============================================================================
CREATE TABLE IF NOT EXISTS account_locks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    locked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NOT NULL COMMENT 'Auto-unlock después de este timestamp',
    attempts_count INT NOT NULL DEFAULT 5,
    lock_reason VARCHAR(50) DEFAULT 'max_attempts' COMMENT 'max_attempts, admin_lock, suspicious_activity',
    unlocked_at DATETIME NULL COMMENT 'Cuando se desbloqueó (manual o automático)',
    unlocked_by INT NULL COMMENT 'User ID que desbloqueó (si fue manual)',
    
    INDEX idx_email (email),
    INDEX idx_locked_until (locked_until),
    UNIQUE KEY unique_active_lock (email, unlocked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP EVENT IF EXISTS cleanup_login_attempts_daily;
DROP PROCEDURE IF EXISTS cleanup_old_login_attempts;

-- =============================================================================
-- Evento programado para limpieza automática diaria (sin procedimiento aparte)
-- =============================================================================
DELIMITER //
CREATE EVENT cleanup_login_attempts_daily
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
        -- Eliminar intentos > 24 horas
        DELETE FROM login_attempts 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
        -- Eliminar locks ya expirados y desbloqueados
        DELETE FROM account_locks 
        WHERE unlocked_at IS NOT NULL 
            AND unlocked_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
END//
DELIMITER ;

-- ============================================================================
-- Índices adicionales para consultas rápidas
-- ============================================================================

-- Contar intentos fallidos recientes por email
CREATE INDEX idx_email_failed_recent ON login_attempts(email, success, created_at);

-- Detectar patrones de ataque por IP
CREATE INDEX idx_ip_failed ON login_attempts(ip_address, success, created_at);

-- ============================================================================
-- Consultas útiles para monitoreo
-- ============================================================================

-- Ver usuarios bloqueados actualmente:
-- SELECT email, locked_at, locked_until, attempts_count 
-- FROM account_locks 
-- WHERE unlocked_at IS NULL AND locked_until > NOW()
-- ORDER BY locked_at DESC;

-- Ver intentos fallidos en última hora:
-- SELECT email, ip_address, COUNT(*) as attempts, MAX(created_at) as last_attempt
-- FROM login_attempts 
-- WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY email, ip_address
-- ORDER BY attempts DESC;

-- Ver IPs con múltiples emails intentados (posible ataque):
-- SELECT ip_address, COUNT(DISTINCT email) as unique_emails, COUNT(*) as attempts
-- FROM login_attempts 
-- WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY ip_address
-- HAVING unique_emails >= 3
-- ORDER BY attempts DESC;
