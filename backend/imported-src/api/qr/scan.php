<?php
/**
 * Endpoint para resolver QR y gestionar puntos de lealtad
 * POST /qr/scan
 * Body JSON: { "qr_code": "...", "action": "add|redeem|none", "points": 10 }
 */

// Allow unauthenticated scans but capture user if provided
$user = Auth::getCurrentUser();
$db = Database::getInstance();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];

$qr = $input['qr_code'] ?? $_GET['qr'] ?? null;

if (empty($qr)) {
    Response::validationError(['qr_code' => 'Se requiere qr_code']);
}

$patient = $db->fetchOne('SELECT * FROM patients WHERE qr_code = ?', [$qr]);
if (!$patient) {
    Response::notFound('Código QR no asociado a ningún paciente');
}

$action = $input['action'] ?? 'none';
$points = isset($input['points']) ? intval($input['points']) : 0;

try {
    if ($action === 'add') {
        if ($points <= 0) {
            Response::validationError(['points' => 'Se requieren puntos válidos para añadir']);
        }

        $db->execute('UPDATE patients SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE id = ?', [$points, $patient['id']]);

        if (class_exists('Audit')) {
            Audit::log('qr_add_points', 'patient', $patient['id'], [
                'qr' => $qr,
                'points' => $points,
                'performed_by' => $user['user_id'] ?? null
            ]);
        }

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patient['id']]);
        Response::success($patient, 'Puntos añadidos por QR');
    }

    if ($action === 'redeem') {
        if ($points <= 0) {
            Response::validationError(['points' => 'Se requieren puntos válidos para canjear']);
        }

        if ($patient['loyalty_points'] < $points) {
            Response::error('Puntos insuficientes', 400);
        }

        $db->execute('UPDATE patients SET loyalty_points = loyalty_points - ?, updated_at = NOW() WHERE id = ?', [$points, $patient['id']]);

        if (class_exists('Audit')) {
            Audit::log('qr_redeem_points', 'patient', $patient['id'], [
                'qr' => $qr,
                'points' => $points,
                'performed_by' => $user['user_id'] ?? null
            ]);
        }

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patient['id']]);
        Response::success($patient, 'Puntos canjeados por QR');
    }

    // Default: just return patient info
    if (class_exists('Audit')) {
        Audit::log('qr_scanned', 'patient', $patient['id'], [
            'qr' => $qr,
            'performed_by' => $user['user_id'] ?? null
        ]);
    }

    Response::success($patient, 'Paciente encontrado por QR');

} catch (Exception $e) {
    error_log('QR scan error: ' . $e->getMessage());
    Response::error('Error al procesar QR');
}

?>
