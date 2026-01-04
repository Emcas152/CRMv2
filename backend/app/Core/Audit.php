<?php
namespace App\Core;

class Audit
{
    /**
     * Registra una acción en el audit log
     * 
     * @param string $action Acción realizada (CREATE, UPDATE, DELETE, etc)
     * @param string $resource_type Tipo de recurso (users, patients, documents, etc)
     * @param int $resource_id ID del recurso
     * @param array $meta Información adicional
     * @return bool
     */
    public static function log($action, $resource_type = null, $resource_id = null, $meta = [])
    {
        try {
            $db = Database::getInstance();

            $user = Auth::getCurrentUser();
            $user_id = $user['user_id'] ?? null;

            $meta_json = json_encode($meta);
            
            // Obtener IP del cliente (con soporte para proxies)
            $ip_address = self::getClientIp();

            $db->execute(
                'INSERT INTO audit_logs (user_id, action, resource_type, resource_id, meta, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$user_id, $action, $resource_type, $resource_id, $meta_json, $ip_address]
            );
            
            return true;
        } catch (\Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene la IP real del cliente (con soporte para proxies)
     * 
     * @return string IP del cliente (IPv4 o IPv6)
     */
    private static function getClientIp(): string
    {
        // IP directa del cliente
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            return '0.0.0.0';
        }
        
        // Si viene a través de proxy, usar X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }
        // Alternativa: X-Real-IP (nginx)
        elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        // CloudFlare
        elseif (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        
        // Validar que sea una IP válida
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Obtiene audits por IP (para detectar patrones de ataque)
     * 
     * @param string $ip_address IP a buscar
     * @param int $hours Últimas N horas (default 24)
     * @return array
     */
    public static function getByIP(string $ip_address, int $hours = 24): array
    {
        try {
            $db = Database::getInstance();
            
            $result = $db->fetchAll(
                'SELECT user_id, action, resource_type, COUNT(*) as count, MAX(created_at) as last_action
                 FROM audit_logs
                 WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 GROUP BY user_id, action, resource_type
                 ORDER BY last_action DESC',
                [$ip_address, $hours]
            );
            
            return $result ?? [];
        } catch (\Exception $e) {
            error_log('Audit getByIP failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Detecta actividad sospechosa (una IP con múltiples usuarios)
     * 
     * @param int $min_unique_users Mínimo de usuarios únicos (default 3)
     * @param int $hours Ventana de tiempo en horas (default 24)
     * @return array IPs sospechosas
     */
    public static function detectSuspiciousIPs(int $min_unique_users = 3, int $hours = 24): array
    {
        try {
            $db = Database::getInstance();
            
            $result = $db->fetchAll(
                'SELECT ip_address, COUNT(DISTINCT user_id) as unique_users, COUNT(*) as total_actions, MAX(created_at) as last_action
                 FROM audit_logs
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 GROUP BY ip_address
                 HAVING unique_users >= ?
                 ORDER BY total_actions DESC',
                [$hours, $min_unique_users]
            );
            
            return $result ?? [];
        } catch (\Exception $e) {
            error_log('Audit detectSuspiciousIPs failed: ' . $e->getMessage());
            return [];
        }
    }
}

