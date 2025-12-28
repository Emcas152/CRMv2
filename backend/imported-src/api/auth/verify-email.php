<?php
/**
 * Endpoint para verificar email
 * GET /verify-email?token=...
 * or POST with JSON { token: '...' }
 */

$db = Database::getInstance();

$token = $_GET['token'] ?? null;
if (!$token) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $token = $data['token'] ?? null;
}

if (empty($token)) {
    Response::validationError(['token' => 'Token requerido']);
}

$user = $db->fetchOne('SELECT * FROM users WHERE email_verification_token = ? LIMIT 1', [$token]);
if (!$user) {
    Response::notFound('Token inválido o expirado');
}

try {
    $db->execute('UPDATE users SET email_verified = 1, email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?', [$user['id']]);

    if (class_exists('Audit')) {
        Audit::log('verify_email', 'user', $user['id'], ['email' => $user['email']]);
    }

    Response::success(['user_id' => $user['id'], 'email' => $user['email']], 'Email verificado correctamente');
} catch (Exception $e) {
    error_log('Verify email error: ' . $e->getMessage());
    Response::error('Error al verificar el email');
}

?>
