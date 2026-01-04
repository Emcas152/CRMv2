-- ============================================================================
-- PHASE 2: RATE LIMITING - Database Schema
-- ============================================================================
-- Tabla para almacenar contadores de rate limiting por usuario e IP
--
-- Estrategia:
--   - 100 requests/minuto por usuario autenticado
--   - 1000 requests/hora por IP
--   - Limpieza automática de contadores expirados
-- ============================================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL COMMENT 'user_id:123 o ip:192.168.1.1',
    identifier_type ENUM('user', 'ip') NOT NULL,
    endpoint VARCHAR(255) DEFAULT '*' COMMENT 'Endpoint específico o * para global',
    request_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Inicio de ventana de tiempo',
    window_end DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fin de ventana (auto-calculado)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_rate_limit (identifier, identifier_type, endpoint, window_start),
    INDEX idx_identifier (identifier, identifier_type),
    INDEX idx_window_end (window_end),
    INDEX idx_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drop existing routine/event for idempotent re-runs
DROP EVENT IF EXISTS cleanup_rate_limits_every_5min;
DROP PROCEDURE IF EXISTS cleanup_expired_rate_limits;

DELIMITER //

CREATE PROCEDURE cleanup_expired_rate_limits()
BEGIN
    -- Eliminar contadores cuya ventana ya expiró
    DELETE FROM rate_limits 
    WHERE window_end < NOW();
END//

DELIMITER ;

DELIMITER //
CREATE EVENT cleanup_rate_limits_every_5min
ON SCHEDULE EVERY 5 MINUTE
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_expired_rate_limits();//
DELIMITER ;

-- ============================================================================
-- Índices adicionales para consultas rápidas
-- ============================================================================

-- Buscar contadores activos por usuario
CREATE INDEX idx_user_active ON rate_limits(identifier, window_end);

-- Buscar contadores activos por IP
CREATE INDEX idx_ip_active ON rate_limits(identifier, window_end);

-- ============================================================================
-- Consultas útiles para monitoreo
-- ============================================================================

-- Ver usuarios que excedieron el límite:
-- SELECT identifier, endpoint, request_count, window_start, window_end
-- FROM rate_limits
-- WHERE identifier_type = 'user' AND request_count >= 100
-- ORDER BY request_count DESC;

-- Ver IPs con alto tráfico:
-- SELECT identifier, SUM(request_count) as total_requests, COUNT(*) as windows
-- FROM rate_limits
-- WHERE identifier_type = 'ip' AND window_end > NOW()
-- GROUP BY identifier
-- ORDER BY total_requests DESC
-- LIMIT 20;

-- Ver endpoints más solicitados:
-- SELECT endpoint, SUM(request_count) as total_requests
-- FROM rate_limits
-- WHERE window_end > NOW()
-- GROUP BY endpoint
-- ORDER BY total_requests DESC
-- LIMIT 10;
