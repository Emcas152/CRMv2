<?php
/**
 * Clase de Autenticación JWT
 */

class Auth
{
    private static $secretKey;

    private static function debugEnabled(): bool
    {
        $env = getenv('APP_ENV') ?: 'local';
        if ($env !== 'production') return true;

        $flag = getenv('APP_DEBUG_AUTH');
        return filter_var($flag ?: false, FILTER_VALIDATE_BOOLEAN);
    }

    private static function debugLog(string $message): void
    {
        if (!self::debugEnabled()) return;
        try {
            error_log($message);
        } catch (Throwable $_) {
            // never block request flow on logging
        }
    }

    public static function init()
    {
        $config = require __DIR__ . '/../config/app.php';
        self::$secretKey = $config['secret_key'];
    }

    /**
     * Genera un token JWT
     */
    public static function generateToken($userId, $userEmail, $userRole)
    {
        self::init();

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $config = require __DIR__ . '/../config/app.php';
        
        $payload = json_encode([
            'user_id' => $userId,
            'email' => $userEmail,
            'role' => $userRole,
            'iat' => time(),
            'exp' => time() + $config['jwt_expiration']
        ]);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secretKey, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Verifica y decodifica un token JWT
     */
    public static function verifyToken($token)
    {
        self::init();

        if (!$token) {
            self::debugLog('verifyToken: Token is empty or null');
            return false;
        }

        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            self::debugLog('verifyToken: Token does not have 3 parts. Parts count: ' . count($tokenParts));
            return false;
        }

        list($header, $payload, $signature) = $tokenParts;

        $validSignature = hash_hmac('sha256', $header . "." . $payload, self::$secretKey, true);
        $validSignature = self::base64UrlEncode($validSignature);

        if ($signature !== $validSignature) {
            self::debugLog('verifyToken: Signature mismatch');
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($payload), true);

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            self::debugLog('verifyToken: Token expired. Exp: ' . ($payload['exp'] ?? 'NOT SET') . ', Now: ' . time());
            return false;
        }

        self::debugLog('verifyToken: Token valid! User ID: ' . ($payload['user_id'] ?? 'UNKNOWN'));
        return $payload;
    }

    /**
     * Obtiene el token del header Authorization
     */
    public static function getTokenFromHeader()
    {
        // Debug is gated by env (APP_DEBUG_AUTH) to avoid production hangs due to excessive logging.
        self::debugLog('=== TOKEN DEBUG ===');
        self::debugLog('$_SERVER[HTTP_AUTHORIZATION]: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        self::debugLog('$_SERVER[REDIRECT_HTTP_AUTHORIZATION]: ' . ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        
        // Intentar desde $_SERVER primero
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                     $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 
                     '';
        
        // Si no está, intentar apache_request_headers si existe
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            self::debugLog('apache_request_headers(): ' . json_encode($apacheHeaders));
            $authHeader = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
        }
        
        // Si no está, intentar getallheaders
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            self::debugLog('getallheaders(): ' . json_encode($headers));
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        
        self::debugLog('Final authHeader: ' . ($authHeader ?: 'EMPTY'));
        self::debugLog('=== END TOKEN DEBUG ===');

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Obtiene el usuario actual del token
     */
    public static function getCurrentUser()
    {
        $token = self::getTokenFromHeader();
        self::debugLog('=== getCurrentUser DEBUG ===');
        self::debugLog('Extracted token: ' . ($token ? '[present]' : 'NULL'));
        
        $result = self::verifyToken($token);
        self::debugLog('verifyToken result: ' . ($result ? '[valid]' : 'FALSE'));
        self::debugLog('=== END getCurrentUser DEBUG ===');
        
        return $result;
    }
    
    /**
     * Valida el token y devuelve el usuario o false
     * Alias de getCurrentUser para compatibilidad
     */
    public static function validateToken()
    {
        return self::getCurrentUser();
    }

    /**
     * Verifica si el usuario está autenticado
     */
    public static function check()
    {
        return self::getCurrentUser() !== false;
    }

    /**
     * Requiere autenticación
     */
    public static function requireAuth()
    {
        if (!self::check()) {
            Response::unauthorized('Token inválido o expirado');
        }
    }

    /**
     * Verifica si el usuario tiene un rol específico
     */
    public static function hasRole($role)
    {
        $user = self::getCurrentUser();
        return $user && $user['role'] === $role;
    }

    /**
     * Requiere un rol específico
     */
    public static function requireRole($role)
    {
        self::requireAuth();
        
        if (!self::hasRole($role)) {
            Response::forbidden('No tienes permisos para esta acción');
        }
    }

    /**
     * Hash de contraseña
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verificar contraseña
     */
    public static function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    // Helpers
    private static function base64UrlEncode($text)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }

    private static function base64UrlDecode($text)
    {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $text));
    }
}
