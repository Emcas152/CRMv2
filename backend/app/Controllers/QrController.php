<?php
namespace App\Controllers;

use App\Core\Request;

class QrController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($action = null)
    {
        self::initCore();

        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            if ($action === 'scan' && in_array($method, ['POST', 'GET'], true)) {
                return $this->scan();
            }

            \App\Core\Response::error('Endpoint no encontrado', 404);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function scan()
    {
        // QR is part of operations; require authentication
        \App\Core\Auth::requireAuth();

        $input = Request::body();

        $qr = $input['qr_code'] ?? ($_GET['qr'] ?? null);
        if (empty($qr)) {
            \App\Core\Response::validationError(['qr_code' => 'Se requiere qr_code']);
        }

        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        $patient = $db->fetchOne('SELECT * FROM patients WHERE qr_code = ? LIMIT 1', [$qr]);
        if (!$patient) {
            \App\Core\Response::notFound('Código QR no asociado a ningún paciente');
        }

        $action = $input['action'] ?? 'none';
        $points = isset($input['points']) ? intval($input['points']) : 0;

        // Mutating actions restricted to ops roles
        if (in_array($action, ['add', 'redeem'], true)) {
            \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para modificar puntos por QR');
        }

        try {
            if ($action === 'add') {
                if ($points <= 0) {
                    \App\Core\Response::validationError(['points' => 'Se requieren puntos válidos para añadir']);
                }

                $db->execute('UPDATE patients SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE id = ?', [$points, $patient['id']]);

                if (class_exists('\\App\\Core\\Audit')) {
                    \App\Core\Audit::log('qr_add_points', 'patient', $patient['id'], [
                        'qr' => $qr,
                        'points' => $points,
                        'performed_by' => is_array($user) ? ($user['user_id'] ?? null) : null,
                    ]);
                }

                $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patient['id']]);
                \App\Core\Response::success($patient, 'Puntos añadidos por QR');
            }

            if ($action === 'redeem') {
                if ($points <= 0) {
                    \App\Core\Response::validationError(['points' => 'Se requieren puntos válidos para canjear']);
                }

                if (intval($patient['loyalty_points'] ?? 0) < $points) {
                    \App\Core\Response::error('Puntos insuficientes', 400);
                }

                $db->execute('UPDATE patients SET loyalty_points = loyalty_points - ?, updated_at = NOW() WHERE id = ?', [$points, $patient['id']]);

                if (class_exists('\\App\\Core\\Audit')) {
                    \App\Core\Audit::log('qr_redeem_points', 'patient', $patient['id'], [
                        'qr' => $qr,
                        'points' => $points,
                        'performed_by' => is_array($user) ? ($user['user_id'] ?? null) : null,
                    ]);
                }

                $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patient['id']]);
                \App\Core\Response::success($patient, 'Puntos canjeados por QR');
            }

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('qr_scanned', 'patient', $patient['id'], [
                    'qr' => $qr,
                    'performed_by' => is_array($user) ? ($user['user_id'] ?? null) : null,
                ]);
            }

            \App\Core\Response::success($patient, 'Paciente encontrado por QR');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al procesar QR', $e);
        }
    }
}
