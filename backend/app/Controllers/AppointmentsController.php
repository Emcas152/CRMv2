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
        $role = (string)($user['role'] ?? '');

        // Patients do not have access to appointments per role rules.
        if ($role === 'patient') {
            \App\Core\Response::forbidden('No tienes permisos para acceder a citas');
        }

        // Staff/doctor/admin/superadmin: full module access
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para acceder a citas');

        if ($method === 'GET' && !$id) {
            return $this->index($user);
        }

        if ($method === 'GET' && $id) {
            return $this->show($id, $user);
        }

        if ($method === 'POST' && !$id) {
            return $this->store($input);
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
        $role = (string)($user['role'] ?? '');

        $query = 'SELECT a.id, a.patient_id, a.staff_member_id, a.service, a.status, a.notes,
              CONCAT(a.appointment_date, " ", a.appointment_time) as appointment_date,
              a.appointment_time,
              a.created_at, a.updated_at,
              p.name as patient_name, p.email as patient_email
              FROM appointments a
              LEFT JOIN patients p ON a.patient_id = p.id
              WHERE 1=1';
        $params = [];

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId) {
                \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
            $query .= ' AND a.patient_id = ?';
            $params[] = $patientId;
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

        if (isset($_GET['patient_id'])) {
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
        \App\Core\Response::success(['data' => $appointments, 'total' => count($appointments)]);
    }

    private function show($id, $user)
    {
        $db = \App\Core\Database::getInstance();
        $role = (string)($user['role'] ?? '');

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

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'doctor', 'staff'], true)) {
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

        try {
            $dateParts = explode(' ', $input['appointment_date']);
            $dateOnly = $dateParts[0];

            $db->execute(
                'INSERT INTO appointments (patient_id, staff_member_id, appointment_date, appointment_time, service, notes, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $input['patient_id'],
                    $input['staff_member_id'] ?? null,
                    $dateOnly,
                    $input['appointment_time'],
                    $input['service'],
                    $input['notes'] ?? null,
                    $input['status'] ?? 'pending'
                ]
            );

            $appointmentId = $db->lastInsertId();
            $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$appointmentId]);

            \App\Core\Response::success($appointment, 'Cita creada exitosamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear cita', $e);
        }
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




