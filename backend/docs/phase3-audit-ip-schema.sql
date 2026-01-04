-- ============================================================================
-- PHASE 3.1: IP LOGGING EN AUDITORÍA - Database Schema
-- ============================================================================
-- Agregar columna ip_address a audit_logs para registrar IP del cliente
-- Esto permite rastrear de dónde vienen las acciones
--
-- Features:
--   - Registrar IP real (con soporte para proxies)
--   - Índices para búsquedas rápidas por IP
--   - Auditoría completa de acciones por IP
-- ============================================================================

-- Agregar columna de IP a tabla audit_logs
ALTER TABLE audit_logs 
ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL COMMENT 'IPv4 o IPv6 del cliente' AFTER user_id;

-- Índices para búsquedas rápidas
CREATE INDEX IF NOT EXISTS idx_audit_ip ON audit_logs(ip_address);
CREATE INDEX IF NOT EXISTS idx_audit_ip_created ON audit_logs(ip_address, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_user_ip ON audit_logs(user_id, ip_address, created_at);

-- ============================================================================
-- Consultas útiles para análisis
-- ============================================================================

-- Ver últimas acciones por IP:
-- SELECT ip_address, user_id, action, resource_type, COUNT(*) as count
-- FROM audit_logs
-- WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY ip_address, user_id, action
-- ORDER BY count DESC;

-- Detectar IPs con actividad sospechosa (múltiples usuarios):
-- SELECT ip_address, COUNT(DISTINCT user_id) as unique_users, COUNT(*) as total_actions
-- FROM audit_logs
-- WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
-- GROUP BY ip_address
-- HAVING unique_users >= 3
-- ORDER BY total_actions DESC;

-- Actividad de un usuario específico:
-- SELECT ip_address, action, resource_type, created_at
-- FROM audit_logs
-- WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
-- ORDER BY created_at DESC;
