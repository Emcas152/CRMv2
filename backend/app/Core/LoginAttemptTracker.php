<?php

namespace App\Core;

use PDO;

/**
 * LoginAttemptTracker
 * 
 * Rastrea intentos de login y gestiona bloqueos automáticos de cuentas
 * para prevenir ataques de fuerza bruta.
 * 
 * Features:
 * - Registro de todos los intentos (exitosos y fallidos)
 * - Bloqueo automático después de 5 intentos fallidos
 * - Desbloqueo automático después de 15 minutos
 * - Detección de patrones de ataque por IP
 * - Auditoría completa con user agent e IP
 * 
 * @package App\Core
 */
class LoginAttemptTracker
{
    private $db;
    
    // Configuración de bloqueo
    const MAX_ATTEMPTS = 5;              // Máximo intentos antes de bloquear
    const LOCKOUT_DURATION = 15;         // Minutos de bloqueo
    const ATTEMPT_WINDOW = 15;           // Ventana de tiempo para contar intentos (minutos)
    const CLEANUP_AFTER_HOURS = 24;      // Limpiar intentos después de X horas
    
    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Registra un intento de login
     * 
     * @param string $email Email del usuario
     * @param bool $success Si el intento fue exitoso
     * @param string|null $failureReason Razón del fallo (invalid_password, user_not_found, etc)
     * @return bool
     */
    public function recordAttempt(string $email, bool $success, ?string $failureReason = null): bool
    {
        $ip = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts 
                (email, ip_address, user_agent, success, failure_reason, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $email,
                $ip,
                $userAgent,
                $success ? 1 : 0,
                $failureReason
            ]);
            
            // Si falló, verificar si debe bloquearse
            if (!$success) {
                $this->checkAndLockAccount($email);
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error recording login attempt: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica si una cuenta está bloqueada
     * 
     * @param string $email Email del usuario
     * @return bool
     */
    public function isAccountLocked(string $email): bool
    {
        try {
            // Verificar bloqueo activo en tabla account_locks
            $stmt = $this->db->prepare("
                SELECT id, locked_until 
                FROM account_locks 
                WHERE email = ? 
                  AND unlocked_at IS NULL 
                  AND locked_until > NOW()
                LIMIT 1
            ");
            
            $stmt->execute([$email]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lock) {
                return true;
            }
            
            // Verificar también por conteo de intentos recientes (backup)
            $attempts = $this->getRecentFailedAttempts($email);
            return $attempts >= self::MAX_ATTEMPTS;
            
        } catch (\PDOException $e) {
            error_log("Error checking account lock: " . $e->getMessage());
            // En caso de error, no bloquear (fail open)
            return false;
        }
    }
    
    /**
     * Obtiene el número de intentos fallidos recientes
     * 
     * @param string $email Email del usuario
     * @return int
     */
    public function getRecentFailedAttempts(string $email): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as attempts
                FROM login_attempts
                WHERE email = ?
                  AND success = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            
            $stmt->execute([$email, self::ATTEMPT_WINDOW]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int) ($result['attempts'] ?? 0);
        } catch (\PDOException $e) {
            error_log("Error counting failed attempts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Verifica si debe bloquearse la cuenta y la bloquea si es necesario
     * 
     * @param string $email Email del usuario
     * @return bool True si se bloqueó la cuenta
     */
    private function checkAndLockAccount(string $email): bool
    {
        $attempts = $this->getRecentFailedAttempts($email);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->lockAccount($email, $attempts);
        }
        
        return false;
    }
    
    /**
     * Bloquea una cuenta
     * 
     * @param string $email Email del usuario
     * @param int $attemptCount Número de intentos que causaron el bloqueo
     * @param string $reason Razón del bloqueo
     * @return bool
     */
    public function lockAccount(string $email, int $attemptCount = 5, string $reason = 'max_attempts'): bool
    {
        try {
            // Verificar si ya existe un bloqueo activo
            if ($this->isAccountLocked($email)) {
                return true; // Ya está bloqueado
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO account_locks 
                (email, locked_at, locked_until, attempts_count, lock_reason)
                VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?)
            ");
            
            $stmt->execute([
                $email,
                self::LOCKOUT_DURATION,
                $attemptCount,
                $reason
            ]);
            
            // Auditoría
            $this->logLockEvent($email, 'ACCOUNT_LOCKED', $attemptCount);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error locking account: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Desbloquea una cuenta manualmente
     * 
     * @param string $email Email del usuario
     * @param int|null $unlockedBy User ID que desbloquea (admin)
     * @return bool
     */
    public function unlockAccount(string $email, ?int $unlockedBy = null): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE account_locks 
                SET unlocked_at = NOW(),
                    unlocked_by = ?
                WHERE email = ?
                  AND unlocked_at IS NULL
            ");
            
            $stmt->execute([$unlockedBy, $email]);
            
            // Auditoría
            $this->logLockEvent($email, 'ACCOUNT_UNLOCKED', 0, $unlockedBy);
            
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("Error unlocking account: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene información de bloqueo activo
     * 
     * @param string $email Email del usuario
     * @return array|null
     */
    public function getLockInfo(string $email): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    id,
                    locked_at,
                    locked_until,
                    attempts_count,
                    lock_reason,
                    TIMESTAMPDIFF(MINUTE, NOW(), locked_until) as minutes_remaining
                FROM account_locks
                WHERE email = ?
                  AND unlocked_at IS NULL
                  AND locked_until > NOW()
                LIMIT 1
            ");
            
            $stmt->execute([$email]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $lock ?: null;
        } catch (\PDOException $e) {
            error_log("Error getting lock info: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Limpia intentos de login antiguos
     * 
     * @return int Número de registros eliminados
     */
    public function cleanupOldAttempts(): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            
            $stmt->execute([self::CLEANUP_AFTER_HOURS]);
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log("Error cleaning up old attempts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Detecta IPs con comportamiento sospechoso (múltiples emails)
     * 
     * @param int $minUniqueEmails Mínimo de emails únicos para considerar sospechoso
     * @param int $minutesWindow Ventana de tiempo en minutos
     * @return array Lista de IPs sospechosas
     */
    public function getSuspiciousIPs(int $minUniqueEmails = 3, int $minutesWindow = 60): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    ip_address,
                    COUNT(DISTINCT email) as unique_emails,
                    COUNT(*) as total_attempts,
                    MAX(created_at) as last_attempt
                FROM login_attempts
                WHERE success = 0
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                GROUP BY ip_address
                HAVING unique_emails >= ?
                ORDER BY total_attempts DESC
            ");
            
            $stmt->execute([$minutesWindow, $minUniqueEmails]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("Error getting suspicious IPs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Resetea intentos después de login exitoso
     * 
     * @param string $email Email del usuario
     * @return bool
     */
    public function resetAttempts(string $email): bool
    {
        try {
            // No eliminamos, solo marcamos como exitoso el login
            // Los intentos antiguos se limpiarán automáticamente
            return true;
        } catch (\Exception $e) {
            error_log("Error resetting attempts: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene la IP del cliente (maneja proxies)
     * 
     * @return string
     */
    private function getClientIp(): string
    {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Puede contener múltiples IPs, tomar la primera
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Validar formato IPv4 o IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Registra evento de bloqueo/desbloqueo en audit_logs
     * 
     * @param string $email Email afectado
     * @param string $action ACCOUNT_LOCKED o ACCOUNT_UNLOCKED
     * @param int $attempts Número de intentos
     * @param int|null $userId Usuario que realizó la acción (para unlock manual)
     */
    private function logLockEvent(string $email, string $action, int $attempts, ?int $userId = null): void
    {
        try {
            $meta = [
                'email' => $email,
                'attempts' => $attempts,
                'ip_address' => $this->getClientIp(),
                'lockout_duration_minutes' => self::LOCKOUT_DURATION
            ];
            
            if ($userId) {
                $meta['unlocked_by_user_id'] = $userId;
            }
            
            Audit::log(
                $userId ?? 0,
                $action,
                'user_account',
                0,
                $meta
            );
        } catch (\Exception $e) {
            error_log("Error logging lock event: " . $e->getMessage());
        }
    }
}
