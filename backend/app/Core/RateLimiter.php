<?php

namespace App\Core;

use PDO;

/**
 * RateLimiter
 * 
 * Middleware para prevenir abuso de la API mediante limitación de requests.
 * 
 * Límites configurables:
 * - 100 requests/minuto por usuario autenticado
 * - 1000 requests/hora por IP
 * - Límites personalizados por endpoint
 * 
 * Headers de respuesta (RFC 6585):
 * - X-RateLimit-Limit: Límite máximo de requests
 * - X-RateLimit-Remaining: Requests restantes en ventana actual
 * - X-RateLimit-Reset: Timestamp cuando se resetea el contador
 * - Retry-After: Segundos hasta que se permita nuevo request (si excedió)
 * 
 * @package App\Core
 */
class RateLimiter
{
    private $db;
    
    // Configuración de límites
    const LIMIT_USER_PER_MINUTE = 100;       // Requests por minuto (usuario autenticado)
    const LIMIT_IP_PER_HOUR = 1000;          // Requests por hora (por IP)
    const LIMIT_GUEST_PER_MINUTE = 20;       // Requests por minuto (usuario no autenticado)
    
    const WINDOW_MINUTE = 60;                // 60 segundos
    const WINDOW_HOUR = 3600;                // 3600 segundos
    
    // Endpoints con límites especiales (más estrictos)
    const SPECIAL_LIMITS = [
        '/api/v1/auth/login' => [
            'limit' => 5,
            'window' => 60,
            'type' => 'ip'  // Solo por IP para prevenir brute force
        ],
        '/api/v1/auth/register' => [
            'limit' => 3,
            'window' => 300,  // 5 minutos
            'type' => 'ip'
        ],
        '/api/v1/auth/forgot-password' => [
            'limit' => 3,
            'window' => 3600,  // 1 hora
            'type' => 'ip'
        ]
    ];
    
    public function __construct(PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Verifica si un request está permitido bajo rate limits
     * 
     * @param string|null $userId User ID (null si no autenticado)
     * @param string|null $endpoint Endpoint específico (opcional)
     * @return bool True si permitido, False si excedió límite
     */
    public function check(?string $userId = null, ?string $endpoint = null): bool
    {
        $ip = $this->getClientIp();
        $endpoint = $endpoint ?? $_SERVER['REQUEST_URI'] ?? '*';
        
        // Verificar límite especial para endpoint si existe
        if (isset(self::SPECIAL_LIMITS[$endpoint])) {
            return $this->checkSpecialLimit($endpoint, $ip);
        }
        
        // Verificar límite por usuario (si autenticado)
        if ($userId) {
            return $this->checkUserLimit($userId, $endpoint);
        }
        
        // Verificar límite por IP (usuario no autenticado o adicional)
        return $this->checkIPLimit($ip, $endpoint);
    }
    
    /**
     * Middleware principal - verifica y actualiza contadores
     * Retorna array con info de rate limit o false si excedió
     * 
     * @param string|null $userId
     * @param string|null $endpoint
     * @return array|false
     */
    public function handle(?string $userId = null, ?string $endpoint = null)
    {
        $ip = $this->getClientIp();
        $endpoint = $endpoint ?? $this->getCurrentEndpoint();
        
        // Determinar límites aplicables
        $limits = $this->getLimitsForRequest($userId, $endpoint);
        
        foreach ($limits as $limit) {
            $identifier = $limit['identifier'];
            $type = $limit['type'];
            $maxRequests = $limit['max'];
            $windowSeconds = $limit['window'];
            
            // Obtener o crear contador
            $counter = $this->getCounter($identifier, $type, $endpoint, $windowSeconds);
            
            // Verificar si excedió el límite
            if ($counter['request_count'] >= $maxRequests) {
                // Excedió el límite
                $this->setRateLimitHeaders($counter, $maxRequests, $windowSeconds);
                $this->logRateLimitExceeded($identifier, $type, $endpoint, $counter['request_count']);
                return false;
            }
            
            // Incrementar contador
            $this->incrementCounter($identifier, $type, $endpoint, $windowSeconds);
        }
        
        // Obtener contador actualizado para headers
        $mainLimit = $limits[0];
        $counter = $this->getCounter(
            $mainLimit['identifier'],
            $mainLimit['type'],
            $endpoint,
            $mainLimit['window']
        );
        
        $this->setRateLimitHeaders($counter, $mainLimit['max'], $mainLimit['window']);
        
        return [
            'allowed' => true,
            'limit' => $mainLimit['max'],
            'remaining' => max(0, $mainLimit['max'] - $counter['request_count']),
            'reset' => strtotime($counter['window_end'])
        ];
    }
    
    /**
     * Verifica límite de usuario autenticado
     * 
     * @param string $userId
     * @param string $endpoint
     * @return bool
     */
    private function checkUserLimit(string $userId, string $endpoint): bool
    {
        $identifier = "user:{$userId}";
        $counter = $this->getCounter($identifier, 'user', $endpoint, self::WINDOW_MINUTE);
        
        return $counter['request_count'] < self::LIMIT_USER_PER_MINUTE;
    }
    
    /**
     * Verifica límite por IP
     * 
     * @param string $ip
     * @param string $endpoint
     * @return bool
     */
    private function checkIPLimit(string $ip, string $endpoint): bool
    {
        $identifier = "ip:{$ip}";
        $counter = $this->getCounter($identifier, 'ip', $endpoint, self::WINDOW_HOUR);
        
        return $counter['request_count'] < self::LIMIT_IP_PER_HOUR;
    }
    
    /**
     * Verifica límite especial para endpoints críticos
     * 
     * @param string $endpoint
     * @param string $ip
     * @return bool
     */
    private function checkSpecialLimit(string $endpoint, string $ip): bool
    {
        $config = self::SPECIAL_LIMITS[$endpoint];
        $identifier = "ip:{$ip}";
        $counter = $this->getCounter($identifier, 'ip', $endpoint, $config['window']);
        
        return $counter['request_count'] < $config['limit'];
    }
    
    /**
     * Obtiene contador actual de rate limit
     * 
     * @param string $identifier
     * @param string $type
     * @param string $endpoint
     * @param int $windowSeconds
     * @return array
     */
    private function getCounter(string $identifier, string $type, string $endpoint, int $windowSeconds): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    request_count,
                    window_start,
                    window_end,
                    TIMESTAMPDIFF(SECOND, NOW(), window_end) as seconds_until_reset
                FROM rate_limits
                WHERE identifier = ?
                  AND identifier_type = ?
                  AND endpoint = ?
                  AND window_end > NOW()
                ORDER BY window_start DESC
                LIMIT 1
            ");
            
            $stmt->execute([$identifier, $type, $endpoint]);
            $counter = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$counter) {
                // No existe contador activo, retornar contador vacío
                return [
                    'request_count' => 0,
                    'window_start' => date('Y-m-d H:i:s'),
                    'window_end' => date('Y-m-d H:i:s', time() + $windowSeconds),
                    'seconds_until_reset' => $windowSeconds
                ];
            }
            
            return $counter;
        } catch (\PDOException $e) {
            error_log("Error getting rate limit counter: " . $e->getMessage());
            // En caso de error, permitir el request (fail open)
            return [
                'request_count' => 0,
                'window_start' => date('Y-m-d H:i:s'),
                'window_end' => date('Y-m-d H:i:s', time() + $windowSeconds),
                'seconds_until_reset' => $windowSeconds
            ];
        }
    }
    
    /**
     * Incrementa el contador de rate limit
     * 
     * @param string $identifier
     * @param string $type
     * @param string $endpoint
     * @param int $windowSeconds
     * @return bool
     */
    private function incrementCounter(string $identifier, string $type, string $endpoint, int $windowSeconds): bool
    {
        try {
            // Intentar actualizar contador existente
            $stmt = $this->db->prepare("
                UPDATE rate_limits
                SET request_count = request_count + 1,
                    updated_at = NOW()
                WHERE identifier = ?
                  AND identifier_type = ?
                  AND endpoint = ?
                  AND window_end > NOW()
            ");
            
            $stmt->execute([$identifier, $type, $endpoint]);
            
            if ($stmt->rowCount() > 0) {
                return true;
            }
            
            // No existe, crear nuevo contador
            $stmt = $this->db->prepare("
                INSERT INTO rate_limits 
                (identifier, identifier_type, endpoint, request_count, window_start, window_end)
                VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
            ");
            
            $stmt->execute([$identifier, $type, $endpoint, $windowSeconds]);
            
            return true;
        } catch (\PDOException $e) {
            error_log("Error incrementing rate limit counter: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Determina límites aplicables para un request
     * 
     * @param string|null $userId
     * @param string $endpoint
     * @return array
     */
    private function getLimitsForRequest(?string $userId, string $endpoint): array
    {
        $limits = [];
        $ip = $this->getClientIp();
        
        // Límite especial para endpoint si existe
        if (isset(self::SPECIAL_LIMITS[$endpoint])) {
            $config = self::SPECIAL_LIMITS[$endpoint];
            $limits[] = [
                'identifier' => "ip:{$ip}",
                'type' => 'ip',
                'max' => $config['limit'],
                'window' => $config['window']
            ];
            return $limits;  // Solo aplicar límite especial
        }
        
        // Límite por usuario (si autenticado)
        if ($userId) {
            $limits[] = [
                'identifier' => "user:{$userId}",
                'type' => 'user',
                'max' => self::LIMIT_USER_PER_MINUTE,
                'window' => self::WINDOW_MINUTE
            ];
        }
        
        // Límite por IP (siempre aplica)
        $limits[] = [
            'identifier' => "ip:{$ip}",
            'type' => 'ip',
            'max' => $userId ? self::LIMIT_IP_PER_HOUR : self::LIMIT_GUEST_PER_MINUTE,
            'window' => $userId ? self::WINDOW_HOUR : self::WINDOW_MINUTE
        ];
        
        return $limits;
    }
    
    /**
     * Establece headers de rate limit en la respuesta
     * 
     * @param array $counter
     * @param int $maxRequests
     * @param int $windowSeconds
     */
    private function setRateLimitHeaders(array $counter, int $maxRequests, int $windowSeconds): void
    {
        $remaining = max(0, $maxRequests - $counter['request_count']);
        $reset = strtotime($counter['window_end']);
        
        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Remaining: {$remaining}");
        header("X-RateLimit-Reset: {$reset}");
        
        if ($remaining === 0) {
            $retryAfter = max(1, $counter['seconds_until_reset'] ?? $windowSeconds);
            header("Retry-After: {$retryAfter}");
        }
    }
    
    /**
     * Registra exceso de rate limit en audit_logs
     * 
     * @param string $identifier
     * @param string $type
     * @param string $endpoint
     * @param int $requestCount
     */
    private function logRateLimitExceeded(string $identifier, string $type, string $endpoint, int $requestCount): void
    {
        try {
            Audit::log(
                0,  // Sin user_id específico
                'RATE_LIMIT_EXCEEDED',
                'api_endpoint',
                0,
                [
                    'identifier' => $identifier,
                    'type' => $type,
                    'endpoint' => $endpoint,
                    'request_count' => $requestCount,
                    'ip_address' => $this->getClientIp(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                ]
            );
        } catch (\Exception $e) {
            error_log("Error logging rate limit exceeded: " . $e->getMessage());
        }
    }
    
    /**
     * Obtiene el endpoint actual limpio
     * 
     * @return string
     */
    private function getCurrentEndpoint(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remover query string
        $uri = strtok($uri, '?');
        
        // Normalizar endpoints con IDs numéricos
        $uri = preg_replace('#/\d+#', '/{id}', $uri);
        
        return $uri;
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
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Limpia contadores expirados (ejecutar periódicamente)
     * 
     * @return int Número de registros eliminados
     */
    public function cleanupExpired(): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM rate_limits 
                WHERE window_end < NOW()
            ");
            
            $stmt->execute();
            
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            error_log("Error cleaning up rate limits: " . $e->getMessage());
            return 0;
        }
    }
}
