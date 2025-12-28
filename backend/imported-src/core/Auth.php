<?php
/**
 * Clase de Autenticación JWT
 */

class Auth
{
    private static $secretKey;

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
            error_log('verifyToken: Token is empty or null');
            return false;
        }

        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            error_log('verifyToken: Token does not have 3 parts. Parts count: ' . count($tokenParts));
            return false;
        }

        list($header, $payload, $signature) = $tokenParts;

        $validSignature = hash_hmac('sha256', $header . "." . $payload, self::$secretKey, true);
        $validSignature = self::base64UrlEncode($validSignature);

        if ($signature !== $validSignature) {
            error_log('verifyToken: Signature mismatch');
            error_log('Expected: ' . $validSignature);
            error_log('Got: ' . $signature);
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($payload), true);

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            error_log('verifyToken: Token expired. Exp: ' . ($payload['exp'] ?? 'NOT SET') . ', Now: ' . time());
            return false;
        }

        error_log('verifyToken: Token valid! User ID: ' . $payload['user_id']);
        return $payload;
    }

    /**
     * Obtiene el token del header Authorization
     */
    public static function getTokenFromHeader()
    {
        // Debug: registrar todas las fuentes posibles
        error_log('=== TOKEN DEBUG ===');
        error_log('$_SERVER[HTTP_AUTHORIZATION]: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        error_log('$_SERVER[REDIRECT_HTTP_AUTHORIZATION]: ' . ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NOT SET'));
        
        // Intentar desde $_SERVER primero
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                     $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 
                     '';
        
        // Si no está, intentar apache_request_headers si existe
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            error_log('apache_request_headers(): ' . json_encode($apacheHeaders));
            $authHeader = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
        }
        
        // Si no está, intentar getallheaders
        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = getallheaders();
            error_log('getallheaders(): ' . json_encode($headers));
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        
        error_log('Final authHeader: ' . ($authHeader ?: 'EMPTY'));
        error_log('=== END TOKEN DEBUG ===');

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
        error_log('=== getCurrentUser DEBUG ===');
        error_log('Extracted token: ' . ($token ?: 'NULL'));
        
        $result = self::verifyToken($token);
        error_log('verifyToken result: ' . ($result ? json_encode($result) : 'FALSE'));
        error_log('=== END getCurrentUser DEBUG ===');
        
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
