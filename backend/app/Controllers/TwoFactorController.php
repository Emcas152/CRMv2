<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Auth;
use App\Core\Database;
use App\Core\TwoFactorAuth;
use App\Core\Validator;
use App\Core\Sanitizer;
use PDO;

/**
 * TwoFactorController
 * 
 * Endpoints para que los usuarios gestionen su 2FA:
 * - GET /2fa/status - Ver estado actual
 * - POST /2fa/enable - Activar 2FA (devuelve backup codes)
 * - POST /2fa/disable - Desactivar 2FA
 * - GET /2fa/methods - Listar métodos disponibles
 * - POST /2fa/test - Enviar código de prueba
 */
class TwoFactorController
{
    private $db;
    private $twoFactorAuth;

    protected static function initCore(): void
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/TwoFactorAuth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Sanitizer.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }
    
    public function __construct()
    {
        self::initCore();
        $this->db = Database::getInstance();
        $this->twoFactorAuth = new TwoFactorAuth($this->db);
    }
    
    /**
     * GET /api/v1/2fa/status
     * Obtiene el estado del 2FA para el usuario actual
     */
    public function getStatus()
    {
        $userId = Auth::getUserIdFromToken();
        
        if (!$userId) {
            Response::unauthorized(['message' => 'Token inválido o expirado']);
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    two_factor_enabled,
                    two_factor_method,
                    email,
                    phone
                FROM users
                WHERE id = ?
            ");
            
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                Response::notFound(['message' => 'Usuario no encontrado']);
            }
            
            // Contar backup codes disponibles
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as available_codes
                FROM two_factor_backup_codes
                WHERE user_id = ? AND used = 0
            ");
            $stmt->execute([$userId]);
            $backupCodesCount = $stmt->fetch(PDO::FETCH_ASSOC)['available_codes'];
            
            Response::success([
                'enabled' => (bool) $user['two_factor_enabled'],
                'method' => $user['two_factor_method'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'backup_codes_available' => (int) $backupCodesCount,
                'available_methods' => TwoFactorAuth::AVAILABLE_METHODS
            ]);
            
        } catch (\PDOException $e) {
            error_log("Error getting 2FA status: " . $e->getMessage());
            Response::error(['message' => 'Error al obtener estado de 2FA'], 500);
        }
    }
    
    /**
     * POST /api/v1/2fa/enable
     * Activa el 2FA para el usuario
     * 
     * Body: {
     *   "method": "email|sms|whatsapp",
     *   "recipient": "email@example.com|+1234567890"
     * }
     */
    public function enable()
    {
        $userId = Auth::getUserIdFromToken();
        
        if (!$userId) {
            Response::unauthorized(['message' => 'Token inválido o expirado']);
        }
        
        $input = Request::body();
        
        $validator = Validator::make($input, [
            'method' => 'required|string',
            'recipient' => 'string'  // Opcional si ya tiene email/phone en perfil
        ]);
        
        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }
        
        $method = Sanitizer::string($input['method']);
        
        // Validar método
        if (!in_array($method, TwoFactorAuth::AVAILABLE_METHODS)) {
            Response::validationError([
                'message' => 'Método inválido',
                'available_methods' => TwoFactorAuth::AVAILABLE_METHODS
            ]);
        }
        
        // Validar que SMS y WhatsApp no estén disponibles aún
        if (in_array($method, [TwoFactorAuth::METHOD_SMS, TwoFactorAuth::METHOD_WHATSAPP])) {
            Response::error([
                'message' => "El método '$method' requiere configuración adicional del servidor",
                'available_now' => [TwoFactorAuth::METHOD_EMAIL]
            ], 501); // 501 Not Implemented
        }
        
        // Obtener recipient del input o del perfil
        $recipient = $input['recipient'] ?? null;
        
        if (!$recipient) {
            $stmt = $this->db->prepare("SELECT email, phone FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $recipient = ($method === TwoFactorAuth::METHOD_EMAIL) 
                ? $user['email'] 
                : $user['phone'];
        }
        
        if (!$recipient) {
            Response::validationError([
                'message' => "No se encontró destinatario para el método '$method'"
            ]);
        }
        
        // Activar 2FA
        $result = $this->twoFactorAuth->enable($userId, $method);
        
        if (!$result) {
            Response::error(['message' => 'Error al activar 2FA'], 500);
        }
        
        Response::success([
            'message' => '2FA activado correctamente',
            'method' => $method,
            'backup_codes' => $result['backup_codes'],
            'warning' => '⚠️ Guarde estos códigos de respaldo en un lugar seguro. No se mostrarán nuevamente.'
        ], 201);
    }
    
    /**
     * POST /api/v1/2fa/disable
     * Desactiva el 2FA para el usuario
     * 
     * Body: {
     *   "password": "password"  // Confirmar con contraseña
     * }
     */
    public function disable()
    {
        $userId = Auth::getUserIdFromToken();
        
        if (!$userId) {
            Response::unauthorized(['message' => 'Token inválido o expirado']);
        }
        
        $input = Request::body();
        
        $validator = Validator::make($input, [
            'password' => 'required|string'
        ]);
        
        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }
        
        // Verificar contraseña actual
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($input['password'], $user['password'])) {
            Response::unauthorized(['message' => 'Contraseña incorrecta']);
        }
        
        // Desactivar 2FA
        $success = $this->twoFactorAuth->disable($userId);
        
        if (!$success) {
            Response::error(['message' => 'Error al desactivar 2FA'], 500);
        }
        
        Response::success([
            'message' => '2FA desactivado correctamente'
        ]);
    }
    
    /**
     * GET /api/v1/2fa/methods
     * Lista los métodos disponibles
     */
    public function getMethods()
    {
        Response::success([
            'methods' => [
                [
                    'id' => TwoFactorAuth::METHOD_EMAIL,
                    'name' => 'Correo Electrónico',
                    'description' => 'Recibir código por email',
                    'available' => true,
                    'icon' => '📧'
                ],
                [
                    'id' => TwoFactorAuth::METHOD_SMS,
                    'name' => 'SMS',
                    'description' => 'Recibir código por mensaje de texto',
                    'available' => false,  // Requiere configuración
                    'icon' => '📱',
                    'requires' => 'Configuración de Twilio o proveedor SMS'
                ],
                [
                    'id' => TwoFactorAuth::METHOD_WHATSAPP,
                    'name' => 'WhatsApp',
                    'description' => 'Recibir código por WhatsApp',
                    'available' => false,  // Requiere configuración
                    'icon' => '💬',
                    'requires' => 'WhatsApp Business API'
                ]
            ]
        ]);
    }
    
    /**
     * POST /api/v1/2fa/test
     * Envía un código de prueba (solo si 2FA está habilitado)
     */
    public function testCode()
    {
        $userId = Auth::getUserIdFromToken();
        
        if (!$userId) {
            Response::unauthorized(['message' => 'Token inválido o expirado']);
        }
        
        // Verificar que 2FA esté habilitado
        if (!$this->twoFactorAuth->isEnabled($userId)) {
            Response::error(['message' => '2FA no está habilitado'], 400);
        }
        
        // Obtener método y recipient
        $stmt = $this->db->prepare("
            SELECT two_factor_method, email, phone 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $method = $user['two_factor_method'];
        $recipient = ($method === TwoFactorAuth::METHOD_EMAIL) 
            ? $user['email'] 
            : $user['phone'];
        
        // Generar y enviar código
        $result = $this->twoFactorAuth->generateCode($userId, $recipient, $method);
        
        if (!$result) {
            Response::error(['message' => 'Error al enviar código de prueba'], 500);
        }
        
        Response::success([
            'message' => 'Código enviado correctamente',
            'method' => $method,
            'recipient' => $this->maskRecipient($recipient, $method),
            'expires_in_minutes' => TwoFactorAuth::CODE_VALIDITY_MINUTES
        ]);
    }
    
    /**
     * POST /api/v1/2fa/regenerate-backup-codes
     * Regenera los códigos de respaldo
     */
    public function regenerateBackupCodes()
    {
        $userId = Auth::getUserIdFromToken();
        
        if (!$userId) {
            Response::unauthorized(['message' => 'Token inválido o expirado']);
        }
        
        $input = Request::body();
        
        // Confirmar con contraseña
        $validator = Validator::make($input, [
            'password' => 'required|string'
        ]);
        
        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }
        
        // Verificar contraseña
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($input['password'], $user['password'])) {
            Response::unauthorized(['message' => 'Contraseña incorrecta']);
        }
        
        // Regenerar códigos
        $newCodes = $this->twoFactorAuth->generateBackupCodes($userId);
        
        Response::success([
            'message' => 'Códigos de respaldo regenerados',
            'backup_codes' => $newCodes,
            'warning' => '⚠️ Los códigos anteriores ya no son válidos. Guarde estos nuevos códigos.'
        ]);
    }
    
    /**
     * Enmascara el recipient para mostrar solo parte
     * 
     * @param string $recipient
     * @param string $method
     * @return string
     */
    private function maskRecipient(string $recipient, string $method): string
    {
        if ($method === TwoFactorAuth::METHOD_EMAIL) {
            $parts = explode('@', $recipient);
            $name = $parts[0];
            $domain = $parts[1] ?? '';
            
            $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
            
            return $maskedName . '@' . $domain;
        } else {
            // Para teléfonos
            return substr($recipient, 0, 4) . str_repeat('*', strlen($recipient) - 6) . substr($recipient, -2);
        }
    }
}
