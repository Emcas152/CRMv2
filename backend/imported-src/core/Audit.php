<?php
/**
 * Helper simple para auditoría de acciones
 */
class Audit
{
    public static function log($action, $resource_type = null, $resource_id = null, $meta = [])
    {
        try {
            $db = Database::getInstance();

            // Obtener usuario actual si existe
            $user = Auth::getCurrentUser();
            $user_id = $user['user_id'] ?? null;

            $meta_json = json_encode($meta);

            $db->execute(
                'INSERT INTO audit_logs (user_id, action, resource_type, resource_id, meta, created_at) VALUES (?, ?, ?, ?, ?, datetime("now"))',
                [$user_id, $action, $resource_type, $resource_id, $meta_json]
            );
        } catch (Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}
