<?php

namespace App\Core;

use PDO;

/**
 * TwoFactorAuth
 * 
 * Gestiona autenticación de dos factores (2FA) mediante códigos por email, SMS o WhatsApp.
 * 
 * Features:
 * - Generación de códigos de 6 dígitos
 * - Envío por email, SMS o WhatsApp
 * - Validez de 5 minutos
 * - Backup codes para recuperación
 * - Tracking de intentos de verificación
 * - Opcional por usuario
 * 
 * @package App\Core
 */
class TwoFactorAuth
{
    private $db;
    
    const CODE_LENGTH = 6;               // Longitud del código
    const CODE_VALIDITY_MINUTES = 5;     // Validez del código en minutos
    const MAX_VERIFICATION_ATTEMPTS = 3; // Máximo intentos antes de invalidar código
    const BACKUP_CODES_COUNT = 10;       // Número de backup codes a generar
    
    // Métodos disponibles
    const METHOD_EMAIL = 'email';
    const METHOD_SMS = 'sms';
    const METHOD_WHATSAPP = 'whatsapp';
    
    const AVAILABLE_METHODS = [
        self::METHOD_EMAIL,
        self::METHOD_SMS,
        self::METHOD_WHATSAPP
    ];
    
    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Verifica si un usuario tiene 2FA habilitado
     * 
     * @param int $userId
     * @return bool
     */
    public function isEnabled(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT two_factor_enabled 
                FROM users 
                WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (bool) ($result['two_factor_enabled'] ?? false);
        } catch (\PDOException $e) {
            error_log("Error checking 2FA status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Habilita 2FA para un usuario
     * 
     * @param int $userId
     * @param string $method Método de 2FA (email, sms, whatsapp)
     * @return array|false Array con backup codes si éxito, false si error
     */
    public function enable(int $userId, string $method = 'email')
    {
        // Validar método
        if (!in_array($method, self::AVAILABLE_METHODS)) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET two_factor_enabled = 1,
                    two_factor_method = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$method, $userId]);
            
            // Generar backup codes
            $backupCodes = $this->generateBackupCodes($userId);
            
            // Auditoría
            Audit::log($userId, '2FA_ENABLED', 'user_account', $userId, [
                'method' => $method
            ]);
            
            return [
                'success' => true,
                'method' => $method,
                'backup_codes' => $backupCodes
            ];
        } catch (\PDOException $e) {
            error_log("Error enabling 2FA: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deshabilita 2FA para un usuario
     * 
     * @param int $userId
     * @return bool
     */
    public function disable(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE users 
                SET two_factor_enabled = 0
                WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            
            // Eliminar códigos y backup codes
            $this->deleteUserCodes($userId);
            $this->deleteBackupCodes($userId);
            
            // Auditoría
            Audit::log($userId, '2FA_DISABLED', 'user_account', $userId);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error disabling 2FA: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera y envía un código de verificación
     * 
     * @param int $userId
     * @param string $recipient Email o teléfono del usuario
     * @param string $method Método de envío (email, sms, whatsapp)
     * @return array ['code' => string, 'expires_at' => string] o false
     */
    public function generateCode(int $userId, string $recipient, string $method = 'email')
    {
        // Invalidar códigos anteriores no usados
        $this->invalidatePreviousCodes($userId);
        
        // Generar código aleatorio de 6 dígitos
        $code = $this->generateRandomCode();
        
        // Calcular expiración
        $expiresAt = date('Y-m-d H:i:s', time() + (self::CODE_VALIDITY_MINUTES * 60));
        
        // Guardar en base de datos
        try {
            $stmt = $this->db->prepare("
                INSERT INTO two_factor_codes 
                (user_id, code, method, ip_address, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $code,
                $method,
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                $expiresAt
            ]);
            
            // Enviar código según el método
            $sent = $this->sendCode($recipient, $code, $method);
            
            if (!$sent) {
                error_log("Failed to send 2FA code to: $recipient via $method");
                return false;
            }
            
            return [
                'code' => $code,  // Solo para testing, no retornar en producción
                'expires_at' => $expiresAt,
                'method' => $method
            ];
            
        } catch (\PDOException $e) {
            error_log("Error generating 2FA code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica un código de autenticación
     * 
     * @param int $userId
     * @param string $code Código a verificar
     * @return bool
     */
    public function verifyCode(int $userId, string $code): bool
    {
        try {
            // Buscar código válido
            $stmt = $this->db->prepare("
                SELECT id, expires_at 
                FROM two_factor_codes
                WHERE user_id = ?
                  AND code = ?
                  AND verified = 0
                  AND expires_at > NOW()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            
            $stmt->execute([$userId, $code]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                // Código no encontrado o expirado
                Audit::log($userId, '2FA_VERIFICATION_FAILED', 'authentication', $userId, [
                    'reason' => 'invalid_or_expired_code',
                    'code_provided' => substr($code, 0, 2) . '****'  // Log parcial por seguridad
                ]);
                return false;
            }
            
            // Marcar como verificado
            $stmt = $this->db->prepare("
                UPDATE two_factor_codes
                SET verified = 1,
                    verified_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$result['id']]);
            
            // Auditoría exitosa
            Audit::log($userId, '2FA_VERIFICATION_SUCCESS', 'authentication', $userId);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("Error verifying 2FA code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica un backup code
     * 
     * @param int $userId
     * @param string $backupCode
     * @return bool
     */
    public function verifyBackupCode(int $userId, string $backupCode): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id 
                FROM two_factor_backup_codes
                WHERE user_id = ?
                  AND code = ?
                  AND used = 0
                LIMIT 1
            ");
            
            $stmt->execute([$userId, $backupCode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                Audit::log($userId, '2FA_BACKUP_CODE_FAILED', 'authentication', $userId);
                return false;
            }
            
            // Marcar como usado
            $stmt = $this->db->prepare("
                UPDATE two_factor_backup_codes
                SET used = 1,
                    used_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$result['id']]);
            
            Audit::log($userId, '2FA_BACKUP_CODE_USED', 'authentication', $userId);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("Error verifying backup code: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera backup codes para un usuario
     * 
     * @param int $userId
     * @return array Lista de códigos generados
     */
    public function generateBackupCodes(int $userId): array
    {
        try {
            // Eliminar códigos antiguos no usados
            $this->deleteBackupCodes($userId, false);  // Solo no usados
            
            $codes = [];
            
            for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
                $code = $this->generateBackupCode();
                
                $stmt = $this->db->prepare("
                    INSERT INTO two_factor_backup_codes (user_id, code)
                    VALUES (?, ?)
                ");
                
                $stmt->execute([$userId, $code]);
                $codes[] = $code;
            }
            
            return $codes;
            
        } catch (\PDOException $e) {
            error_log("Error generating backup codes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene backup codes disponibles de un usuario
     * 
     * @param int $userId
     * @return array
     */
    public function getAvailableBackupCodes(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT code 
                FROM two_factor_backup_codes
                WHERE user_id = ?
                  AND used = 0
                ORDER BY created_at DESC
            ");
            
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } catch (\PDOException $e) {
            error_log("Error getting backup codes: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Genera un código aleatorio de 6 dígitos
     * 
     * @return string
     */
    private function generateRandomCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
    
    /**
     * Genera un backup code de 8 caracteres (formato: XXXX-XXXX)
     * 
     * @return string
     */
    private function generateBackupCode(): string
    {
        $part1 = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $part2 = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "{$part1}-{$part2}";
    }
    
    /**
     * Envía código según el método seleccionado
     * 
     * @param string $recipient Email o teléfono
     * @param string $code
     * @param string $method
     * @return bool
     */
    private function sendCode(string $recipient, string $code, string $method): bool
    {
        switch ($method) {
            case self::METHOD_EMAIL:
                return $this->sendCodeByEmail($recipient, $code);
            
            case self::METHOD_SMS:
                return $this->sendCodeBySMS($recipient, $code);
            
            case self::METHOD_WHATSAPP:
                return $this->sendCodeByWhatsApp($recipient, $code);
            
            default:
                return false;
        }
    }
    
    /**
     * Envía código por email
     * 
     * @param string $email
     * @param string $code
     * @return bool
     */
    private function sendCodeByEmail(string $email, string $code): bool
    {
        try {
            $subject = "Código de verificación - CRM";
            $body = "
                <h2>Código de verificación</h2>
                <p>Su código de verificación es:</p>
                <h1 style='font-size: 32px; letter-spacing: 5px; color: #333;'>{$code}</h1>
                <p>Este código expira en " . self::CODE_VALIDITY_MINUTES . " minutos.</p>
                <p>Si no solicitó este código, ignore este mensaje.</p>
            ";
            
            Mailer::send($email, $subject, $body);
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Error sending 2FA code email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envía código por SMS
     * 
     * @param string $phone Número de teléfono
     * @param string $code
     * @return bool
     */
    private function sendCodeBySMS(string $phone, string $code): bool
    {
        try {
            // TODO: Integrar con proveedor de SMS (Twilio, Vonage, etc.)
            // Ejemplo con Twilio:
            // $twilioAccountSid = getenv('TWILIO_ACCOUNT_SID');
            // $twilioAuthToken = getenv('TWILIO_AUTH_TOKEN');
            // $twilioPhone = getenv('TWILIO_PHONE_NUMBER');
            // 
            // $client = new \\Twilio\\Rest\\Client($twilioAccountSid, $twilioAuthToken);
            // $message = $client->messages->create(
            //     $phone,
            //     [
            //         'from' => $twilioPhone,
            //         'body' => \"Su código de verificación CRM es: {$code}. Válido por \" . self::CODE_VALIDITY_MINUTES . \" minutos.\"
            //     ]
            // );
            
            // Por ahora, log del mensaje (reemplazar con integración real)
            error_log("SMS 2FA Code to {$phone}: {$code}");
            
            // IMPORTANTE: Retornar false hasta que se configure un proveedor real
            // return true;
            return false;
            
        } catch (\Exception $e) {
            error_log("Error sending 2FA SMS: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Envía código por WhatsApp
     * 
     * @param string $phone Número de teléfono (formato internacional)
     * @param string $code
     * @return bool
     */
    private function sendCodeByWhatsApp(string $phone, string $code): bool
    {
        try {
            // TODO: Integrar con API de WhatsApp Business
            // Opciones:
            // 1. WhatsApp Business API oficial (requiere aprobación)
            // 2. Twilio WhatsApp API
            // 3. MessageBird
            // 
            // Ejemplo con Twilio WhatsApp:
            // $twilioAccountSid = getenv('TWILIO_ACCOUNT_SID');
            // $twilioAuthToken = getenv('TWILIO_AUTH_TOKEN');
            // $twilioWhatsApp = getenv('TWILIO_WHATSAPP_NUMBER'); // ej: +14155238886
            // 
            // $client = new \\Twilio\\Rest\\Client($twilioAccountSid, $twilioAuthToken);
            // $message = $client->messages->create(
            //     \"whatsapp:{$phone}\",
            //     [
            //         'from' => \"whatsapp:{$twilioWhatsApp}\",
            //         'body' => \"🔐 *CRM Verificación*\\n\\nSu código es: *{$code}*\\n\\nVálido por \" . self::CODE_VALIDITY_MINUTES . \" minutos.\"
            //     ]
            // );
            
            // Por ahora, log del mensaje (reemplazar con integración real)
            error_log("WhatsApp 2FA Code to {$phone}: {$code}");
            
            // IMPORTANTE: Retornar false hasta que se configure un proveedor real
            // return true;
            return false;
            
        } catch (\Exception $e) {
            error_log("Error sending 2FA WhatsApp: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalida códigos anteriores no usados
     * 
     * @param int $userId
     * @return bool
     */
    private function invalidatePreviousCodes(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE two_factor_codes
                SET expires_at = NOW()
                WHERE user_id = ?
                  AND verified = 0
                  AND expires_at > NOW()
            ");
            
            $stmt->execute([$userId]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error invalidating previous codes: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina todos los códigos de un usuario
     * 
     * @param int $userId
     * @return bool
     */
    private function deleteUserCodes(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM two_factor_codes
                WHERE user_id = ?
            ");
            
            $stmt->execute([$userId]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error deleting user codes: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina backup codes de un usuario
     * 
     * @param int $userId
     * @param bool $all Si true elimina todos, si false solo no usados
     * @return bool
     */
    private function deleteBackupCodes(int $userId, bool $all = true): bool
    {
        try {
            if ($all) {
                $stmt = $this->db->prepare("
                    DELETE FROM two_factor_backup_codes
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
            } else {
                $stmt = $this->db->prepare("
                    DELETE FROM two_factor_backup_codes
                    WHERE user_id = ? AND used = 0
                ");
                $stmt->execute([$userId]);
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error deleting backup codes: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene la IP del cliente
     * 
     * @return string
     */
    private function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Limpia códigos expirados (ejecutar periódicamente)
     * 
     * @return int Número de registros eliminados
     */
    public function cleanupExpired(): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM two_factor_codes 
                WHERE expires_at < NOW() AND verified = 0
            ");
            
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log("Error cleaning up 2FA codes: " . $e->getMessage());
            return 0;
        }
    }
}
