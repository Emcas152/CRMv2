<?php
/**
 * Endpoints de Citas (Appointments)
 */

Auth::requireAuth();

$user = Auth::getCurrentUser();
$db = Database::getInstance();

// GET /appointments - Listar citas
if ($method === 'GET' && !$id) {
    $query = 'SELECT a.id, a.patient_id, a.staff_member_id, a.service, a.status, a.notes,
              CONCAT(a.appointment_date, " ", a.appointment_time) as appointment_date,
              a.appointment_time,
              a.created_at, a.updated_at,
              p.name as patient_name, p.email as patient_email
              FROM appointments a
              LEFT JOIN patients p ON a.patient_id = p.id
              WHERE 1=1';
    $params = [];

    // Filtros
    if (isset($_GET['date'])) {
        $query .= ' AND DATE(a.appointment_date) = ?';
        $params[] = $_GET['date'];
    }

    // Filtro de rango de fechas
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
    Response::success(['data' => $appointments, 'total' => count($appointments)]);
}

// GET /appointments/{id} - Ver cita
if ($method === 'GET' && $id) {
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
        Response::notFound('Cita no encontrada');
    }

    Response::success($appointment);
}

// POST /appointments - Crear cita
if ($method === 'POST' && !$id) {
    // Solo admin y staff pueden crear citas
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden('No tienes permisos para crear citas');
    }

    $validator = Validator::make($input, [
        'patient_id' => 'required|integer',
        'staff_member_id' => 'nullable|integer',
        'appointment_date' => 'required|string',
        'appointment_time' => 'required|string',
        'service' => 'required|string|max:255',
        'notes' => 'nullable|string|max:1000',
        'status' => 'nullable|in:pending,confirmed,completed,cancelled'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        // Extraer solo la fecha si viene con hora
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

        Response::success($appointment, 'Cita creada exitosamente', 201);
    } catch (Exception $e) {
        error_log('Create appointment error: ' . $e->getMessage());
        Response::error('Error al crear cita: ' . $e->getMessage());
    }
}

// PUT /appointments/{id} - Actualizar cita
if ($method === 'PUT' && $id) {
    $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
    
    if (!$appointment) {
        Response::notFound('Cita no encontrada');
    }

    try {
        $updates = [];
        $params = [];

        if (isset($input['patient_id'])) {
            $updates[] = 'patient_id = ?';
            $params[] = $input['patient_id'];
        }
        if (isset($input['staff_member_id'])) {
            $updates[] = 'staff_member_id = ?';
            $params[] = $input['staff_member_id'];
        }
        if (isset($input['appointment_date'])) {
            $dateParts = explode(' ', $input['appointment_date']);
            $dateOnly = $dateParts[0];
            $updates[] = 'appointment_date = ?';
            $params[] = $dateOnly;
        }
        if (isset($input['appointment_time'])) {
            $updates[] = 'appointment_time = ?';
            $params[] = $input['appointment_time'];
        }
        if (isset($input['service'])) {
            $updates[] = 'service = ?';
            $params[] = $input['service'];
        }
        if (isset($input['notes'])) {
            $updates[] = 'notes = ?';
            $params[] = $input['notes'];
        }
        if (isset($input['status'])) {
            $updates[] = 'status = ?';
            $params[] = $input['status'];
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $query = 'UPDATE appointments SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->execute($query, $params);

        $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
        Response::success($appointment, 'Cita actualizada exitosamente');
    } catch (Exception $e) {
        error_log('Update appointment error: ' . $e->getMessage());
        Response::error('Error al actualizar cita');
    }
}

// DELETE /appointments/{id} - Eliminar cita
if ($method === 'DELETE' && $id) {
    try {
        $db->execute('DELETE FROM appointments WHERE id = ?', [$id]);
        Response::success(null, 'Cita eliminada exitosamente');
    } catch (Exception $e) {
        error_log('Delete appointment error: ' . $e->getMessage());
        Response::error('Error al eliminar cita');
    }
}

// POST /appointments/{id}/update-status - Actualizar solo el estado
if ($method === 'POST' && $id && $action === 'update-status') {
    $validator = Validator::make($input, [
        'status' => 'required|in:pending,confirmed,completed,cancelled'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        $db->execute(
            'UPDATE appointments SET status = ?, updated_at = NOW() WHERE id = ?',
            [$input['status'], $id]
        );

        $appointment = $db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
        Response::success($appointment, 'Estado actualizado exitosamente');
    } catch (Exception $e) {
        error_log('Update status error: ' . $e->getMessage());
        Response::error('Error al actualizar estado');
    }
}

// POST /appointments/{id}/send-email - Enviar recordatorio por email
if ($method === 'POST' && $id && $action === 'send-email') {
    try {
        // Obtener cita con información del paciente
        $appointment = $db->fetchOne(
            'SELECT a.*, p.name as patient_name, p.email as patient_email
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
            [$id]
        );
        
        if (!$appointment) {
            Response::notFound('Cita no encontrada');
        }

        if (!$appointment['patient_email']) {
            Response::error('El paciente no tiene email registrado');
        }

        // Obtener información del staff member si existe
        $staffMember = null;
        if ($appointment['staff_member_id']) {
            $staffMember = $db->fetchOne(
                'SELECT name FROM staff_members WHERE id = ?',
                [$appointment['staff_member_id']]
            );
        }

        // Cargar clase Mailer
        require_once __DIR__ . '/../../core/Mailer.php';
        $mailer = new Mailer();

        // Preparar datos del paciente
        $patient = [
            'name' => $appointment['patient_name'],
            'email' => $appointment['patient_email']
        ];

        // Enviar email
        $sent = $mailer->sendAppointmentReminder($appointment, $patient, $staffMember);

        if ($sent) {
            Response::success([
                'sent' => true,
                'email' => $appointment['patient_email']
            ], 'Recordatorio enviado exitosamente por email');
        } else {
            Response::error('Error al enviar el email. Verifica la configuración SMTP.');
        }
    } catch (Exception $e) {
        error_log('Send email error: ' . $e->getMessage());
        Response::error('Error al enviar recordatorio: ' . $e->getMessage());
    }
}

// POST /appointments/{id}/generate-reminder - Generar recordatorio con IA
if ($method === 'POST' && $id && $action === 'generate-reminder') {
    try {
        // Obtener cita con información del paciente
        $appointment = $db->fetchOne(
            'SELECT a.*, p.name as patient_name, p.email as patient_email
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
            [$id]
        );
        
        if (!$appointment) {
            Response::notFound('Cita no encontrada');
        }

        // Obtener información del staff member si existe
        $staffMember = null;
        if ($appointment['staff_member_id']) {
            $staffMember = $db->fetchOne(
                'SELECT name FROM staff_members WHERE id = ?',
                [$appointment['staff_member_id']]
            );
        }

        // Cargar servicio de OpenAI
        require_once __DIR__ . '/../../core/OpenAIService.php';
        
        try {
            $openai = new OpenAIService();
            
            // Preparar datos del paciente
            $patient = [
                'name' => $appointment['patient_name'],
                'email' => $appointment['patient_email']
            ];

            // Generar recordatorio personalizado
            $reminderText = $openai->generateAppointmentReminder($appointment, $patient, $staffMember);

            Response::success([
                'reminder' => $reminderText,
                'patient_name' => $appointment['patient_name'],
                'appointment_date' => $appointment['appointment_date'],
                'service' => $appointment['service']
            ], 'Recordatorio generado exitosamente con IA');
            
        } catch (Exception $aiError) {
            // Si falla OpenAI, devolver un mensaje genérico
            Response::error('Error al generar recordatorio con IA: ' . $aiError->getMessage() . '. Verifica tu OPENAI_API_KEY en .env');
        }
        
    } catch (Exception $e) {
        error_log('Generate reminder error: ' . $e->getMessage());
        Response::error('Error al generar recordatorio: ' . $e->getMessage());
    }
}

// POST /appointments/{id}/send-whatsapp - Enviar recordatorio por WhatsApp
if ($method === 'POST' && $id && $action === 'send-whatsapp') {
    try {
        // Obtener cita con información del paciente
        $appointment = $db->fetchOne(
            'SELECT a.*, p.name as patient_name, p.email as patient_email, p.phone as patient_phone
             FROM appointments a
             LEFT JOIN patients p ON a.patient_id = p.id
             WHERE a.id = ?',
            [$id]
        );
        
        if (!$appointment) {
            Response::notFound('Cita no encontrada');
        }

        if (!$appointment['patient_phone']) {
            Response::error('El paciente no tiene número de teléfono registrado');
        }

        // Obtener información del staff member si existe
        $staffMember = null;
        if ($appointment['staff_member_id']) {
            $staffMember = $db->fetchOne(
                'SELECT name FROM staff_members WHERE id = ?',
                [$appointment['staff_member_id']]
            );
        }

        // Cargar servicio de Twilio
        require_once __DIR__ . '/../../core/TwilioService.php';
        
        try {
            $twilio = new TwilioService();
            
            // Preparar datos del paciente
            $patient = [
                'name' => $appointment['patient_name'],
                'email' => $appointment['patient_email'],
                'phone' => $appointment['patient_phone']
            ];

            // Enviar WhatsApp
            $result = $twilio->sendAppointmentReminder($appointment, $patient, $staffMember);

            Response::success([
                'sent' => true,
                'phone' => $appointment['patient_phone'],
                'message_sid' => $result['message_sid'] ?? null,
                'status' => $result['status'] ?? 'sent'
            ], 'Recordatorio enviado exitosamente por WhatsApp');
            
        } catch (Exception $twilioError) {
            Response::error('Error al enviar WhatsApp: ' . $twilioError->getMessage() . '. Verifica tus credenciales de Twilio en .env');
        }
        
    } catch (Exception $e) {
        error_log('Send WhatsApp error: ' . $e->getMessage());
        Response::error('Error al enviar recordatorio por WhatsApp: ' . $e->getMessage());
    }
}

Response::error('Método no permitido', 405);
