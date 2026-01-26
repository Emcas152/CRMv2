<?php
/**
 * Endpoint de Usuario Actual (Me)
 */

Auth::requireAuth();

$user = Auth::getCurrentUser();

$db = Database::getInstance();
$userData = $db->fetchOne(
    'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
    [$user['user_id']]
);

if (!$userData) {
    Response::notFound('Usuario no encontrado');
}

// Normalizar rol
$role = strtolower(trim($userData['role'] ?? ''));

$response = [
    'user' => $userData,
    'patient' => [] // siempre devolver patient para evitar crash frontend
];

// Si es paciente, incluir datos del paciente
if (in_array($role, ['patient', 'paciente'])) {
    $patient = $db->fetchOne(
        'SELECT * FROM patients WHERE user_id = ?',
        [$userData['id']]
    );

    $response['patient'] = $patient ?: [];
}

Response::success($response);
