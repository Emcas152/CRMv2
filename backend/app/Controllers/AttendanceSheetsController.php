<?php
namespace App\Controllers;

use App\Core\Request;

class AttendanceSheetsController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Pdf.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];

        try {
            \App\Core\Auth::requireAuth();
            $user = \App\Core\Auth::getCurrentUser();

            if ($method === 'GET' && !$id) {
                return $this->index($user);
            }

            if ($method === 'GET' && $id) {
                if ($action === 'pdf') {
                    return $this->pdf($id, $user);
                }
                return $this->show($id, $user);
            }

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function normalizeRole($role): string
    {
        $r = strtolower((string)($role ?? ''));
        return $r === 'paciente' ? 'patient' : $r;
    }

    private function ensureSchema($db): void
    {
        try {
            $db->execute(
                "CREATE TABLE IF NOT EXISTS attendance_sheets (\n"
                . "  id INT AUTO_INCREMENT PRIMARY KEY,\n"
                . "  patient_id INT NOT NULL,\n"
                . "  product_id INT NOT NULL DEFAULT 0,\n"
                . "  appointment_id INT NOT NULL DEFAULT 0,\n"
                . "  service_name VARCHAR(255) NOT NULL,\n"
                . "  total_sessions INT NOT NULL DEFAULT 0,\n"
                . "  created_at DATETIME NOT NULL,\n"
                . "  updated_at DATETIME NOT NULL,\n"
                . "  UNIQUE KEY uniq_patient_product_service (patient_id, product_id, service_name),\n"
                . "  KEY idx_patient (patient_id)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                []
            );
        } catch (\Exception $e) {
        }
    }

    private function getPatientIdForUser($user, $db): ?int
    {
        $userId = intval($user['user_id'] ?? 0);
        $email = (string)($user['email'] ?? '');
        $row = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? OR (email != "" AND email = ?) LIMIT 1', [$userId, $email]);
        return $row ? intval($row['id']) : null;
    }

    private function index($user)
    {
        $db = \App\Core\Database::getInstance();
        $this->ensureSchema($db);

        $role = $this->normalizeRole($user['role'] ?? '');

        $params = [];
        $query = 'SELECT * FROM attendance_sheets WHERE 1=1';

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId) {
                \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
            $query .= ' AND patient_id = ?';
            $params[] = $patientId;
        } else {
            \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para ver fichas de asistencia');
            if (isset($_GET['patient_id']) && intval($_GET['patient_id']) > 0) {
                $query .= ' AND patient_id = ?';
                $params[] = intval($_GET['patient_id']);
            }
        }

        $query .= ' ORDER BY created_at DESC';

        $rows = $db->fetchAll($query, $params);
        \App\Core\Response::success(['data' => $rows, 'total' => count($rows)]);
    }

    private function show($id, $user)
    {
        $db = \App\Core\Database::getInstance();
        $this->ensureSchema($db);

        $role = $this->normalizeRole($user['role'] ?? '');

        $sheet = $db->fetchOne('SELECT * FROM attendance_sheets WHERE id = ? LIMIT 1', [intval($id)]);
        if (!$sheet) {
            \App\Core\Response::notFound('Ficha de asistencia no encontrada');
        }

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId || intval($sheet['patient_id'] ?? 0) !== $patientId) {
                \App\Core\Response::forbidden('No tienes acceso a esta ficha');
            }
        } else {
            \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos');
        }

        \App\Core\Response::success($sheet);
    }

        private function pdf($id, $user)
        {
                $db = \App\Core\Database::getInstance();
                $this->ensureSchema($db);

                $role = $this->normalizeRole($user['role'] ?? '');

                $sheet = $db->fetchOne('SELECT * FROM attendance_sheets WHERE id = ? LIMIT 1', [intval($id)]);
                if (!$sheet) {
                        \App\Core\Response::notFound('Ficha de asistencia no encontrada');
                }

                if ($role === 'patient') {
                        $patientId = $this->getPatientIdForUser($user, $db);
                        if (!$patientId || intval($sheet['patient_id'] ?? 0) !== $patientId) {
                                \App\Core\Response::forbidden('No tienes acceso a esta ficha');
                        }
                } else {
                        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos');
                }

                $patient = $db->fetchOne('SELECT id, name FROM patients WHERE id = ? LIMIT 1', [intval($sheet['patient_id'] ?? 0)]);
                $patientName = $patient ? (string)$patient['name'] : 'Paciente';

                $serviceName = (string)($sheet['service_name'] ?? '');
                $totalSessions = intval($sheet['total_sessions'] ?? 0);
                if ($totalSessions <= 0) {
                        $totalSessions = 10;
                }

                $createdAt = (string)($sheet['created_at'] ?? '');
                $ref = intval($sheet['appointment_id'] ?? 0);
                if ($ref <= 0) {
                        $ref = intval($sheet['id'] ?? 0);
                }

                $rowsHtml = '';
                for ($i = 1; $i <= $totalSessions; $i++) {
                        $rowsHtml .= '<tr>'
                                . '<td class="col-session">Sesión</td>'
                                . '<td class="col-date">&nbsp;</td>'
                                . '<td class="col-sign">&nbsp;</td>'
                                . '</tr>';
                }

                $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ficha de Asistencia</title>
    <style>
        body{font-family:Arial, sans-serif;font-size:12px;color:#111;margin:24px;}
        h1{font-size:18px;margin:0 0 12px 0;letter-spacing:0.5px;}
        .meta{margin-bottom:14px;}
        .meta table{width:100%;border-collapse:collapse;}
        .meta td{padding:2px 0;}
        .label{width:90px;color:#444;}
        .section-title{font-weight:bold;margin:16px 0 6px 0;}
        .treatment{font-weight:bold;}
        table.sheet{width:100%;border-collapse:collapse;}
        table.sheet th, table.sheet td{border-bottom:1px solid #333;padding:6px 6px;vertical-align:top;}
        table.sheet th{border-top:1px solid #333;text-align:left;}
        .col-session{width:44%;}
        .col-date{width:18%;}
        .col-sign{width:38%;}
    </style>
</head>
<body>
    <h1>FICHA DE ASISTENCIA</h1>

    <div class="meta">
        <table>
            <tr><td class="label">Paciente:</td><td>' . htmlspecialchars($patientName) . '</td></tr>
            <tr><td class="label">Factura:</td><td>' . htmlspecialchars((string)$ref) . '</td></tr>
            <tr><td class="label">Fecha:</td><td>' . htmlspecialchars(date('d/m/Y')) . ($createdAt ? ' (Creada: ' . htmlspecialchars($createdAt) . ')' : '') . '</td></tr>
        </table>
    </div>

    <div class="section-title">TRATAMIENTO</div>
    <div class="treatment">' . htmlspecialchars($serviceName !== '' ? $serviceName : '—') . '</div>

    <table class="sheet">
        <thead>
            <tr>
                <th>SESIÓN</th>
                <th>FECHA</th>
                <th>FIRMA</th>
            </tr>
        </thead>
        <tbody>
            ' . $rowsHtml . '
        </tbody>
    </table>
</body>
</html>';

                $download = isset($_GET['download']) && (string)$_GET['download'] === '1';
                $filename = 'ficha_asistencia_' . $patientName . '_' . ($serviceName !== '' ? $serviceName : 'servicio') . '_ID' . intval($sheet['id'] ?? 0) . '.pdf';
                \App\Core\Pdf::outputFromHtml($html, $filename, $download);
        }
}
