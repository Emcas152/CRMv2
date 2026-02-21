<?php
namespace App\Controllers;

use App\Core\Request;

class AppointmentsController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Mailer.php';
        require_once __DIR__ . '/../Core/OpenAIService.php';
        require_once __DIR__ . '/../Core/TwilioService.php';
        require_once __DIR__ . '/../Core/FieldEncryption.php';
        require_once __DIR__ . '/../Core/Pdf.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        // Appointments are part of CRM/clinical operations
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $role = strtolower((string)($user['role'] ?? ''));
        if ($role === 'paciente') {
            $role = 'patient';
        }

        // Patients: limited access (view own + request/create).
        if ($role === 'patient') {
            if ($method === 'GET' && !$id) {
                return $this->index($user);
            }
            if ($method === 'GET' && $id) {
                if ($action === 'consent-pdf') {
                    return $this->consentPdf($id, $user);
                }
                return $this->show($id, $user);
            }
            if ($method === 'POST' && !$id) {
                return $this->store($input, $user);
            }

            \App\Core\Response::forbidden('No tienes permisos para esta acciİn');
        }

        // Staff/doctor/admin/superadmin: full module access
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para acceder a citas');

        if ($method === 'GET' && !$id) {
            return $this->index($user);
        }

        if ($method === 'GET' && $id) {
            if ($action === 'consent-pdf') {
                return $this->consentPdf($id, $user);
            }
            return $this->show($id, $user);
        }

        if ($method === 'POST' && !$id) {
            return $this->store($input, $user);
        }

        if ($method === 'PUT' && $id) {
            return $this->update($id, $input);
        }

        if ($method === 'DELETE' && $id) {
            return $this->destroy($id);
        }

        if ($method === 'POST' && $id && $action === 'update-status') {
            return $this->updateStatus($id, $input);
        }

        if ($method === 'POST' && $id && $action === 'send-email') {
            return $this->sendEmail($id);
        }

        if ($method === 'POST' && $id && $action === 'generate-reminder') {
            return $this->generateReminder($id);
        }

        if ($method === 'POST' && $id && $action === 'send-whatsapp') {
            return $this->sendWhatsapp($id);
        }

        \App\Core\Response::error('Método no permitido', 405);
    }

    private function decryptPatientFields(array $patient): array
    {
        if (!empty($patient['email_encrypted'])) {
            try {
                $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']);
            } catch (\Throwable $e) {
            }
        }
        if (!empty($patient['phone_encrypted'])) {
            try {
                $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']);
            } catch (\Throwable $e) {
            }
        }
        return $patient;
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
        $role = strtolower((string)($user['role'] ?? ''));
        if ($role === 'paciente') {
            $role = 'patient';
        }

        $query = 'SELECT a.id, a.patient_id, a.staff_member_id, a.service, a.status, a.notes,
              CONCAT(a.appointment_date, " ", a.appointment_time) as appointment_date,
              a.appointment_time,
              a.created_at, a.updated_at,
              p.name as patient_name, p.email as patient_email
              FROM appointments a
              LEFT JOIN patients p ON a.patient_id = p.id
              WHERE 1=1';
        $params = [];

        $patientId = null;
        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId) {
                \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
        }

        // For patients, support a strict mode to return ONLY their own appointments.
        // This is needed for a clean "historial" list without leaking other patients' slots.
        $patientMyOnly = false;
        if ($role === 'patient') {
            $rawMyOnly = $_GET['my_only'] ?? null;
            $patientMyOnly = ($rawMyOnly === '1' || $rawMyOnly === 1 || $rawMyOnly === true || $rawMyOnly === 'true');
            if ($patientMyOnly && $patientId) {
                $query .= ' AND a.patient_id = ?';
                $params[] = $patientId;
            }
        }

        if (isset($_GET['date'])) {
            $query .= ' AND DATE(a.appointment_date) = ?';
            $params[] = $_GET['date'];
        }

        if (isset($_GET['date_from'])) {
            $query .= ' AND DATE(a.appointment_date) >= ?';
            $params[] = $_GET['date_from'];
        }

        if (isset($_GET['date_to'])) {
            $query .= ' AND DATE(a.appointment_date) <= ?';
            $params[] = $_GET['date_to'];
        }

        if (isset($_GET['patient_id']) && $role !== 'patient') {
            $query .= ' AND a.patient_id = ?';
            $params[] = $_GET['patient_id'];
        }

        if (isset($_GET['staff_member_id'])) {
            $query .= ' AND a.staff_member_id = ?';
            $params[] = $_GET['staff_member_id'];
        }

        if (isset($_GET['status'])) {
            $query .= ' AND a.status = ?';
            $params[] = $_GET['status'];
        }

        $query .= ' ORDER BY a.appointment_date ASC, a.appointment_time ASC';

        $appointments = $db->fetchAll($query, $params);

        if ($role === 'patient' && $patientId && !$patientMyOnly) {
            foreach ($appointments as &$appt) {
                if (intval($appt['patient_id'] ?? 0) !== $patientId) {
                    $appt['patient_id'] = 0;
                    $appt['patient_name'] = null;
                    $appt['patient_email'] = null;
                    $appt['service'] = 'Ocupado';
                    $appt['notes'] = null;
                    $appt['staff_member_id'] = null;
                }
            }
        }

        \App\Core\Response::success(['data' => $appointments, 'total' => count($appointments)]);
    }

    private function show($id, $user)
    {
        $db = \App\Core\Database::getInstance();
        $role = strtolower((string)($user['role'] ?? ''));
        if ($role === 'paciente') {
            $role = 'patient';
        }

        $appointment = $db->fetchOne(
            'SELECT a.id, a.patient_id, a.staff_member_id, a.service, a.status, a.notes,
         CONCAT(a.appointment_date, " ", a.appointment_time) as appointment_date,
         a.appointment_time,
         a.created_at, a.updated_at,
         p.name as patient_name, p.email as patient_email
         FROM appointments a
         LEFT JOIN patients p ON a.patient_id = p.id
         WHERE a.id = ?',
            [$id]
        );

        if (!$appointment) {
            \App\Core\Response::notFound('Cita no encontrada');
        }

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId || intval($appointment['patient_id'] ?? 0) !== $patientId) {
                \App\Core\Response::forbidden('No tienes acceso a esta cita');
            }
        }

        \App\Core\Response::success($appointment);
    }

        private function consentPdf($id, $user)
        {
                $db = \App\Core\Database::getInstance();
                $role = strtolower((string)($user['role'] ?? ''));
                if ($role === 'paciente') {
                        $role = 'patient';
                }

                $appointment = $db->fetchOne(
                        'SELECT a.id, a.patient_id, a.staff_member_id, a.service,
                                        a.appointment_date, a.appointment_time,
                                        p.name as patient_name,
                                        sm.name as staff_name
                         FROM appointments a
                         LEFT JOIN patients p ON a.patient_id = p.id
                         LEFT JOIN staff_members sm ON a.staff_member_id = sm.id
                         WHERE a.id = ? LIMIT 1',
                        [intval($id)]
                );

                if (!$appointment) {
                        \App\Core\Response::notFound('Cita no encontrada');
                }

                if ($role === 'patient') {
                        $patientId = $this->getPatientIdForUser($user, $db);
                        if (!$patientId || intval($appointment['patient_id'] ?? 0) !== $patientId) {
                                \App\Core\Response::forbidden('No tienes acceso a esta cita');
                        }
                } else {
                        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos');
                }

                $patient = $db->fetchOne(
                        'SELECT id, name, email, phone, address, dpi, birthday, age,
                                        email_encrypted, phone_encrypted
                         FROM patients WHERE id = ? LIMIT 1',
                        [intval($appointment['patient_id'] ?? 0)]
                );

                if (!$patient) {
                        \App\Core\Response::notFound('Paciente no encontrado');
                }
                $patient = $this->decryptPatientFields($patient);

                $patientName = (string)($patient['name'] ?? ($appointment['patient_name'] ?? ''));
                $dpi = (string)($patient['dpi'] ?? '');
                $address = (string)($patient['address'] ?? '');
                $phone = (string)($patient['phone'] ?? '');
                $serviceName = (string)($appointment['service'] ?? '');
                $staffName = (string)($appointment['staff_name'] ?? '');

                // Allow overriding the applied treatment text if needed.
                $application = isset($_GET['application']) ? trim((string)$_GET['application']) : '';
                if ($application === '') {
                        $application = $serviceName;
                }

                $today = date('d/m/Y');
                $download = isset($_GET['download']) && (string)$_GET['download'] === '1';

                // NOTE: Wording based on the existing backend/public/Ficha de datos.docx template.
                $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Consentimiento Informado</title>
    <style>
        body{font-family:Arial, sans-serif;font-size:12px;color:#111;margin:24px;line-height:1.35;}
        h1{font-size:16px;margin:0 0 10px 0;text-align:center;}
        .field-line{border-bottom:1px solid #111;display:inline-block;min-width:220px;}
        .muted{color:#444;}
        .block{margin:10px 0;}
        .sig{margin-top:18px;}
        .sig .line{border-bottom:1px solid #111;height:20px;}
        .two-col{width:100%;border-collapse:collapse;}
        .two-col td{vertical-align:top;padding:4px 6px;}
        .box{border:1px solid #ddd;padding:10px;border-radius:4px;}
    </style>
</head>
<body>
    <h1>CONSENTIMIENTO INFORMADO PARA REALIZAR TRATAMIENTO ESTÉTICO</h1>

    <div class="block">
        Yo <span class="field-line">' . htmlspecialchars($patientName) . '</span> mayor de edad
    </div>

    <div class="block">
        Con DPI <span class="field-line">' . htmlspecialchars($dpi) . '</span>
        con domicilio en <span class="field-line">' . htmlspecialchars($address) . '</span>
    </div>

    <div class="block">
        Teléfono <span class="field-line">' . htmlspecialchars($phone) . '</span>
        Dirección <span class="field-line">' . htmlspecialchars($address) . '</span>
    </div>

    <div class="block"><strong>Declara:</strong></div>

    <div class="block">
        Haber sido ampliamente informada(o) acerca del tratamiento, que consiste en la aplicación de
        <span class="field-line">' . htmlspecialchars($application) . '</span>
    </div>

    <div class="block">El tratamiento consiste en trabajar las siguientes zonas:</div>
    <table class="two-col">
        <tr>
            <td>Cuerpo: <span class="field-line">&nbsp;</span></td>
            <td>Rostro: <span class="field-line">&nbsp;</span></td>
        </tr>
    </table>

    <div class="box">
        <div class="block">Que el tratamiento se realizará con tecnología proporcionada por Centro V MEDICAL SPA.</div>
        <div class="block">No estar afectado por ninguna patología o situación contraindicado en el tratamiento, tales como patologías de riñón, cáncer, hígado, corazón, circulación periférica, alteraciones metabólicas, alteraciones cutáneas, ni estar en tratamiento con anticoagulantes, no estar ni tener sospecha de estar embarazada, NO tener implantes metálicos.</div>
        <div class="block">Estar informada(o) que deberá adaptarse al protocolo recomendado.</div>
        <div class="block">Que el éxito de cada tratamiento dependerá de la constancia de cada paciente.</div>
        <div class="block">Haber sido informada(o) de los posibles efectos secundarios, tales como leve edema, eritema cutáneo, hematoma o dolencia en el lugar de la aplicación, y que la duración puede variar entre 2 a 8 días posteriores a la sesión.</div>
        <div class="block">Que el centro V MEDICAL SPA me ha ofrecido la suficiente información e indicaciones relativas a tener en cuenta con la finalidad de favorecer la normal recuperación, de evitar complicaciones y no interferir en el éxito del tratamiento, tales como tomar suficiente líquido durante el transcurso del tratamiento, realizar ejercicio cardiovascular de 2 a 3 veces por semana, especialmente dentro de las 24 horas de haber realizado los procedimientos corporales.</div>
        <div class="block">Seguir la guía de alimentación y restricciones calóricas (alcohol) proporcionada por la Nutricionista.</div>
        <div class="block">Haber sido informado acerca de la posibilidad de que no haciendo caso a dichas indicaciones podría perjudicar al éxito del tratamiento.</div>
        <div class="block">Que me han informado que el porcentaje de mejora del área a corregir, su duración y la simetría del resultado, dependen no solo de las técnicas empleadas sino también de las respuestas del organismo y del comportamiento de la/el paciente.</div>
        <div class="block">Que debo reservar la hora de la sesión con anterioridad y que si por algún motivo no puedo asistir, debo avisar preferiblemente el día anterior, sabiendo que si acudo 10 minutos después de la hora citada no me podrán realizar el tratamiento a no ser que el centro V MEDICAL SPA tenga espacio disponible.</div>
        <div class="block">Hago constar que V Medical Spa NO se hace responsable de cualquier aspecto NO declarado en este compromiso, liberando totalmente de responsabilidades médicas y económicas a V Medical Spa.</div>
        <div class="block">Autorizo a V MEDICAL SPA a efectuar fotografías pre y post-tratamiento, que se utilizarán exclusivamente con la finalidad de seguimiento correspondiente y para evaluar los resultados y siempre respetando mi privacidad.</div>
    </div>

    <div class="block">
        Confirmo que he leído, entendido y aceptado los términos del presente compromiso, sus lineamientos, recomendaciones y reglamentos acerca y enterado(a) de todos sus efectos legales.
    </div>

    <div class="block">
        Firmo aceptando el mismo a los <span class="field-line">&nbsp;</span> días del mes de <span class="field-line">&nbsp;</span> de <span class="field-line">&nbsp;</span>.
        <span class="muted">(Fecha actual: ' . htmlspecialchars($today) . ')</span>
    </div>

    <div class="sig">
        <div class="line"></div>
        <div class="muted">Firma y Nombre</div>
    </div>

    <div class="block" style="margin-top:16px;"><strong>AUTENTICA:</strong></div>
    <div class="block">
        En la ciudad de Guatemala, departamento de Guatemala, el <span class="field-line">&nbsp;</span>, como NOTARIO(A) DOY FE: de que las firmas que anteceden del señor(a/ita) (nombre del cliente) <span class="field-line">&nbsp;</span>, quien se identifica con documento personal de identificación, con código único de identificación número <span class="field-line">&nbsp;</span>, extendido por el Registro Nacional de las Personas RENAP del Municipio de <span class="field-line">&nbsp;</span> del departamento de <span class="field-line">&nbsp;</span>, son auténticas por haber sido puestas el día de hoy en mi presencia, en fe de lo cual firmo y sello el presente compromiso de participación y adhiero los timbres de ley.
    </div>

    <table class="two-col" style="margin-top:18px;">
        <tr>
            <td style="width:50%;">
                Firma y nombre del Cliente:
                <div class="line"></div>
            </td>
            <td style="width:50%;">
                Por mí y ante mí:
                <div class="line"></div>
            </td>
        </tr>
    </table>

    <div class="muted" style="margin-top:10px;">
        Cita #' . htmlspecialchars((string)($appointment['id'] ?? '')) . ' · Tratamiento: ' . htmlspecialchars($serviceName) . ($staffName !== '' ? ' · Profesional: ' . htmlspecialchars($staffName) : '') . '
    </div>
</body>
</html>';

                $filename = 'consentimiento_' . $patientName . '_' . ($serviceName !== '' ? $serviceName : 'tratamiento') . '_cita' . intval($appointment['id'] ?? 0) . '.pdf';
                \App\Core\Pdf::outputFromHtml($html, $filename, $download);
        }

    private function store($input, $user = null)
    {
        $user = $user ?: \App\Core\Auth::getCurrentUser();
        $role = strtolower((string)($user['role'] ?? ''));
        if ($role === 'paciente') {
            $role = 'patient';
        }
        if (!in_array($role, ['superadmin', 'admin', 'doctor', 'staff', 'patient'], true)) {
            \App\Core\Response::forbidden('No tienes permisos para crear citas');
        }

        $validator = \App\Core\Validator::make($input, [
            'patient_id' => 'required|integer',
            'staff_member_id' => 'integer',
            'appointment_date' => 'required|string',
            'appointment_time' => 'required|string',
            'service' => 'required|string|max:255',
            'notes' => 'string|max:1000',
            'status' => 'in:pending,confirmed,completed,cancelled'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();
        $patientIdForUser = null;
        if ($role === 'patient') {
            $patientIdForUser = $this->getPatientIdForUser($user, $db);
            if (!$patientIdForUser) {
                \App\Core\Response::forbidden('No tienes permisos para crear citas');
            }
        }

        try {
            $dateParts = explode(' ', $input['appointment_date']);
            $dateOnly = $dateParts[0];

            $appointmentTime = (string)$input['appointment_time'];
            $existing = $db->fetchOne(
                'SELECT id FROM appointments WHERE appointment_date = ? AND appointment_time = ? LIMIT 1',
                [$dateOnly, $appointmentTime]
            );
            if ($existing) {
                \App\Core\Response::conflict('Horario ocupado');
            }

            $patientId = $input['patient_id'];
            $status = $input['status'] ?? 'pending';
            $staffMemberId = $input['staff_member_id'] ?? null;
            $notes = $input['notes'] ?? null;

            if ($role === 'patient') {
                $patientId = $patientIdForUser;
                $status = 'pending';
                $staffMemberId = null;
            }

            $db->execute(
                'INSERT INTO appointments (patient_id, staff_member_id, appointment_date, appointment_time, service, notes, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $patientId,
                    $staffMemberId,
                    $dateOnly,
                    $appointmentTime,
                    $input['service'],
                    $notes,
                    $status
                ]
            );

            $appointmentId = $db->lastInsertId();
            $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$appointmentId]);

            // If the booked service is configured to require attendance control,
            // create an attendance sheet for this patient.
            try {
                $this->maybeCreateAttendanceSheet($db, intval($patientId), intval($appointmentId), (string)($input['service'] ?? ''));
            } catch (\Throwable $e) {
                // Do not block appointment creation if attendance sheet creation fails.
                error_log('Attendance sheet creation failed: ' . $e->getMessage());
            }

            \App\Core\Response::success($appointment, 'Cita creada exitosamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear cita', $e);
        }
    }

    private function maybeCreateAttendanceSheet($db, int $patientId, int $appointmentId, string $serviceName): void
    {
        $serviceName = trim($serviceName);
        if ($patientId <= 0 || $appointmentId <= 0 || $serviceName === '') {
            return;
        }

        // Ensure schema exists (best-effort, MySQL-compatible).
        try { $db->execute("ALTER TABLE products ADD COLUMN requires_attendance TINYINT(1) NOT NULL DEFAULT 0", []); } catch (\Exception $e) {}
        try { $db->execute("ALTER TABLE products ADD COLUMN attendance_sessions INT NOT NULL DEFAULT 0", []); } catch (\Exception $e) {}

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
            // If CREATE TABLE fails, abort silently.
            return;
        }

        // Match serviceName to a configured service product.
        $product = $db->fetchOne(
            'SELECT id, requires_attendance, attendance_sessions FROM products WHERE type = ? AND name = ? LIMIT 1',
            ['service', $serviceName]
        );

        $requires = intval($product['requires_attendance'] ?? 0) === 1;
        if (!$requires) {
            return;
        }

        $productId = intval($product['id'] ?? 0);
        $sessions = intval($product['attendance_sessions'] ?? 0);
        if ($sessions < 0) $sessions = 0;

        $existing = $db->fetchOne(
            'SELECT id FROM attendance_sheets WHERE patient_id = ? AND product_id = ? AND service_name = ? LIMIT 1',
            [$patientId, $productId, $serviceName]
        );
        if ($existing) {
            return;
        }

        $db->execute(
            'INSERT INTO attendance_sheets (patient_id, product_id, appointment_id, service_name, total_sessions, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$patientId, $productId, $appointmentId, $serviceName, $sessions]
        );
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
        if (!$appointment) {
            \App\Core\Response::notFound('Cita no encontrada');
        }

        try {
            $updates = [];
            $params = [];

            foreach (['patient_id', 'staff_member_id', 'appointment_time', 'service', 'notes', 'status'] as $field) {
                if (isset($input[$field])) {
                    $updates[] = $field . ' = ?';
                    $params[] = $input[$field];
                }
            }

            if (isset($input['appointment_date'])) {
                $dateParts = explode(' ', $input['appointment_date']);
                $dateOnly = $dateParts[0];
                $updates[] = 'appointment_date = ?';
                $params[] = $dateOnly;
            }

            $updates[] = 'updated_at = NOW()';
            $params[] = $id;

            $query = 'UPDATE appointments SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->execute($query, $params);

            $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
            \App\Core\Response::success($appointment, 'Cita actualizada exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar cita', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();
        try {
            $db->execute('DELETE FROM appointments WHERE id = ?', [$id]);
            \App\Core\Response::success(null, 'Cita eliminada exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar cita', $e);
        }
    }

    private function updateStatus($id, $input)
    {
        $validator = \App\Core\Validator::make($input, [
            'status' => 'required|in:pending,confirmed,completed,cancelled'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();

        try {
            $db->execute(
                'UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ?',
                [$input['status'], $id]
            );

            $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
            \App\Core\Response::success($appointment, 'Estado actualizado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar estado', $e);
        }
    }

    private function sendEmail($id)
    {
        $db = \App\Core\Database::getInstance();

        try {
            $appointment = $db->fetchOne(
                'SELECT a.*, p.name as patient_name, p.email as patient_email
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
                [$id]
            );

            if (!$appointment) {
                \App\Core\Response::notFound('Cita no encontrada');
            }

            if (empty($appointment['patient_email'])) {
                \App\Core\Response::error('El paciente no tiene email registrado');
            }

            $staffMember = null;
            if (!empty($appointment['staff_member_id'])) {
                $staffMember = $db->fetchOne('SELECT name FROM staff_members WHERE id = ?', [$appointment['staff_member_id']]);
            }

            $mailer = new \App\Core\Mailer();

            $patient = [
                'name' => $appointment['patient_name'],
                'email' => $appointment['patient_email'],
            ];

            $sent = $mailer->sendAppointmentReminder($appointment, $patient, $staffMember);

            if ($sent) {
                \App\Core\Response::success([
                    'sent' => true,
                    'email' => $appointment['patient_email'],
                ], 'Recordatorio enviado exitosamente por email');
            }

            \App\Core\Response::error('Error al enviar el email. Verifica la configuración SMTP.');
        } catch (\Exception $e) {
            \App\Core\Response::exception('Error al enviar recordatorio', $e);
        }
    }

    private function generateReminder($id)
    {
        $db = \App\Core\Database::getInstance();

        try {
            $appointment = $db->fetchOne(
                'SELECT a.*, p.name as patient_name, p.email as patient_email
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
                [$id]
            );

            if (!$appointment) {
                \App\Core\Response::notFound('Cita no encontrada');
            }

            $staffMember = null;
            if (!empty($appointment['staff_member_id'])) {
                $staffMember = $db->fetchOne('SELECT name FROM staff_members WHERE id = ?', [$appointment['staff_member_id']]);
            }

            try {
                $openai = new \App\Core\OpenAIService();

                $patient = [
                    'name' => $appointment['patient_name'],
                    'email' => $appointment['patient_email'],
                ];

                $reminderText = $openai->generateAppointmentReminder($appointment, $patient, $staffMember);

                \App\Core\Response::success([
                    'reminder' => $reminderText,
                    'patient_name' => $appointment['patient_name'],
                    'appointment_date' => $appointment['appointment_date'],
                    'service' => $appointment['service'],
                ], 'Recordatorio generado exitosamente con IA');
            } catch (\Exception $aiError) {
                \App\Core\Response::exception('Error al generar recordatorio con IA. Verifica tu OPENAI_API_KEY en .env', $aiError);
            }
        } catch (\Exception $e) {
            \App\Core\Response::exception('Error al generar recordatorio', $e);
        }
    }

    private function sendWhatsapp($id)
    {
        $db = \App\Core\Database::getInstance();

        try {
            $appointment = $db->fetchOne(
                'SELECT a.*, p.name as patient_name, p.email as patient_email, p.phone as patient_phone
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
                [$id]
            );

            if (!$appointment) {
                \App\Core\Response::notFound('Cita no encontrada');
            }

            if (empty($appointment['patient_phone'])) {
                \App\Core\Response::error('El paciente no tiene número de teléfono registrado');
            }

            $staffMember = null;
            if (!empty($appointment['staff_member_id'])) {
                $staffMember = $db->fetchOne('SELECT name FROM staff_members WHERE id = ?', [$appointment['staff_member_id']]);
            }

            try {
                $twilio = new \App\Core\TwilioService();

                $patient = [
                    'name' => $appointment['patient_name'],
                    'email' => $appointment['patient_email'],
                    'phone' => $appointment['patient_phone'],
                ];

                $result = $twilio->sendAppointmentReminder($appointment, $patient, $staffMember);

                \App\Core\Response::success([
                    'sent' => true,
                    'phone' => $appointment['patient_phone'],
                    'message_sid' => $result['message_sid'] ?? null,
                    'status' => $result['status'] ?? 'sent',
                ], 'Recordatorio enviado exitosamente por WhatsApp');
            } catch (\Exception $twilioError) {
                \App\Core\Response::exception('Error al enviar WhatsApp. Verifica tus credenciales de Twilio en .env', $twilioError);
            }
        } catch (\Exception $e) {
            \App\Core\Response::exception('Error al enviar recordatorio por WhatsApp', $e);
        }
    }
}




