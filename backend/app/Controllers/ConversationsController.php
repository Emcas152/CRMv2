<?php
namespace App\Controllers;

use App\Core\Request;

class ConversationsController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
        // Optional
        @require_once __DIR__ . '/../Core/Audit.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        \App\Core\Auth::requireAuth();
        \App\Core\Auth::requireAnyRole(['superadmin', 'doctor', 'patient'], 'No tienes permisos para acceder a conversaciones');

        if ($method === 'GET' && !$id) {
            return $this->index();
        }

        if ($method === 'POST' && !$id) {
            return $this->store($input);
        }

        if ($method === 'GET' && $id && !$action) {
            return $this->show($id);
        }

        if ($method === 'GET' && $id && $action === 'messages') {
            return $this->listMessages($id);
        }

        if ($method === 'POST' && $id && $action === 'messages') {
            return $this->sendMessage($id, $input);
        }

        if ($method === 'POST' && $id && $action === 'read') {
            return $this->markRead($id);
        }

        \App\Core\Response::error('Método no permitido', 405);
    }

    private function ensureParticipant($conversationId, $userId)
    {
        $db = \App\Core\Database::getInstance();
        $row = $db->fetchOne(
            'SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1',
            [intval($conversationId), intval($userId)]
        );
        if (!$row) {
            \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
        }
    }

    private function getPatientIdForUser(array $user): ?int
    {
        $db = \App\Core\Database::getInstance();
        $userId = intval($user['user_id'] ?? 0);
        $email = (string)($user['email'] ?? '');
        $row = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? OR (email != "" AND email = ?) LIMIT 1', [$userId, $email]);
        return $row ? intval($row['id']) : null;
    }

    private function getStaffMemberIdForUser(int $userId): ?int
    {
        $db = \App\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT id FROM staff_members WHERE user_id = ? LIMIT 1', [$userId]);
        return $row ? intval($row['id']) : null;
    }

    private function isDoctorAssignedToPatient(int $doctorUserId, int $patientId): bool
    {
        $db = \App\Core\Database::getInstance();
        $row = $db->fetchOne(
            'SELECT 1
             FROM appointments a
             JOIN staff_members sm ON sm.id = a.staff_member_id
             JOIN users u ON u.id = sm.user_id
             WHERE a.patient_id = ? AND u.role = "doctor" AND u.id = ?
             LIMIT 1',
            [$patientId, $doctorUserId]
        );
        return (bool)$row;
    }

    private function getRolesByUserIds(array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $db = \App\Core\Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $db->fetchAll('SELECT id, role FROM users WHERE id IN (' . $placeholders . ')', $userIds);
        $rolesById = [];
        foreach ($rows as $r) {
            $rolesById[intval($r['id'])] = (string)$r['role'];
        }
        return $rolesById;
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);
        $role = (string)($user['role'] ?? '');

        // List conversations with last message and a simple unread count
        $sql = 'SELECT c.id, c.subject, c.created_by, c.created_at,
               (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_message,
               (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) AS last_message_at,
               (SELECT COUNT(*)
                  FROM messages m
                  JOIN conversation_participants cp ON cp.conversation_id = c.id AND cp.user_id = ?
                 WHERE m.conversation_id = c.id
                   AND (cp.last_read_at IS NULL OR m.created_at > cp.last_read_at)
                   AND m.sender_user_id <> ?) AS unread_count
        FROM conversations c
        JOIN conversation_participants me ON me.conversation_id = c.id AND me.user_id = ?
        WHERE 1=1';

        $params = [$userId, $userId, $userId];

        // Patients: only conversations with assigned doctors
        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user);
            if (!$patientId) {
        \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
            $sql .= ' AND c.id IN (
                SELECT DISTINCT cp.conversation_id
                FROM conversation_participants cp
                JOIN users u ON u.id = cp.user_id AND u.role = "doctor"
                JOIN staff_members sm ON sm.user_id = u.id
                JOIN appointments a ON a.staff_member_id = sm.id AND a.patient_id = ?
                WHERE cp.conversation_id = c.id
              )';
            $params[] = $patientId;
        }

        // Doctors: only conversations with assigned patients
        if ($role === 'doctor') {
            $staffId = $this->getStaffMemberIdForUser($userId);
            if (!$staffId) {
        \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
            $sql .= ' AND c.id IN (
                SELECT DISTINCT cp.conversation_id
                FROM conversation_participants cp
                JOIN patients p ON p.user_id = cp.user_id
                JOIN appointments a ON a.patient_id = p.id AND a.staff_member_id = ?
                WHERE cp.conversation_id = c.id
              )';
            $params[] = $staffId;
        }

        $sql .= ' ORDER BY COALESCE((SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1), c.created_at) DESC';

        try {
            $items = $db->fetchAll($sql, $params);
            \App\Core\Response::success(['data' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar conversaciones', $e);
        }
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);
        $role = (string)($user['role'] ?? '');

        try {
            $this->ensureParticipant($id, $userId);

            // Patients: only conversations with assigned doctors
            if ($role === 'patient') {
        $patientId = $this->getPatientIdForUser($user);
        if (!$patientId) {
            \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
        }
        $hasAssignedDoctor = $db->fetchOne(
            'SELECT 1
             FROM conversation_participants cp
             JOIN users u ON u.id = cp.user_id AND u.role = "doctor"
             JOIN staff_members sm ON sm.user_id = u.id
             JOIN appointments a ON a.staff_member_id = sm.id AND a.patient_id = ?
             WHERE cp.conversation_id = ?
             LIMIT 1',
            [$patientId, intval($id)]
        );
        if (!$hasAssignedDoctor) {
            \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
        }
            }

            // Doctors: only conversations with assigned patients
            if ($role === 'doctor') {
        $staffId = $this->getStaffMemberIdForUser($userId);
        if (!$staffId) {
            \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
        }
        $hasAssignedPatient = $db->fetchOne(
            'SELECT 1
             FROM conversation_participants cp
             JOIN patients p ON p.user_id = cp.user_id
             JOIN appointments a ON a.patient_id = p.id AND a.staff_member_id = ?
             WHERE cp.conversation_id = ?
             LIMIT 1',
            [$staffId, intval($id)]
        );
        if (!$hasAssignedPatient) {
            \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
        }
            }

            $conv = $db->fetchOne('SELECT * FROM conversations WHERE id = ? LIMIT 1', [intval($id)]);
            if (!$conv) {
        \App\Core\Response::notFound('Conversacion no encontrada');
            }

            $participants = $db->fetchAll(
        'SELECT u.id, u.name, u.email, u.role
         FROM conversation_participants cp
         JOIN users u ON u.id = cp.user_id
         WHERE cp.conversation_id = ?
         ORDER BY u.name ASC',
        [intval($id)]
            );

            $conv['participants'] = $participants;
            \App\Core\Response::success($conv);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al obtener conversacion', $e);
        }
    }

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $validator = \App\Core\Validator::make($input, [
            'subject' => 'string|max:255',
            'participant_user_ids' => 'required',
            'first_message' => 'string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $participantIds = $input['participant_user_ids'] ?? [];
        if (!is_array($participantIds)) {
            \App\Core\Response::validationError(['participant_user_ids' => 'Debe ser un arreglo de IDs']);
        }

        $participantIds = array_values(array_unique(array_map('intval', $participantIds)));
        $creatorId = intval($user['user_id']);

        // Always include creator
        if (!in_array($creatorId, $participantIds, true)) {
            $participantIds[] = $creatorId;
        }

        if (count($participantIds) < 2) {
            \App\Core\Response::validationError(['participant_user_ids' => 'Debes incluir al menos 2 participantes']);
        }

        $creatorRole = (string)($user['role'] ?? '');
        if (!in_array($creatorRole, ['patient', 'doctor'], true)) {
            \App\Core\Response::forbidden('Solo pacientes y doctores pueden iniciar conversaciones');
        }

        $rolesById = $this->getRolesByUserIds($participantIds);
        $otherIds = [];
        foreach ($participantIds as $pid) {
            if ($pid !== $creatorId) {
        $otherIds[] = $pid;
            }
        }

        $hasPatient = false;
        $hasDoctor = false;

        foreach ($otherIds as $pid) {
            $role = $rolesById[$pid] ?? '';
            if (!in_array($role, ['patient', 'doctor'], true)) {
        \App\Core\Response::forbidden('Solo pacientes y doctores pueden participar');
            }
            if ($role === 'patient') {
        $hasPatient = true;
            }
            if ($role === 'doctor') {
        $hasDoctor = true;
            }
        }

        if ($creatorRole === 'patient') {
            if ($hasPatient) {
        \App\Core\Response::forbidden('Los pacientes no pueden iniciar conversaciones con otros pacientes');
            }
            if (!$hasDoctor) {
        \App\Core\Response::validationError(['participant_user_ids' => 'Debes incluir un doctor asignado']);
            }
            $patientId = $this->getPatientIdForUser($user);
            if (!$patientId) {
        \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
            }
            foreach ($otherIds as $pid) {
        if (!($rolesById[$pid] ?? '') || ($rolesById[$pid] ?? '') !== 'doctor') {
            \App\Core\Response::forbidden('Solo puedes conversar con doctores asignados');
        }
        if (!$this->isDoctorAssignedToPatient($pid, $patientId)) {
            \App\Core\Response::forbidden('Solo puedes conversar con tu doctor asignado');
        }
            }
        }

        if ($creatorRole === 'doctor') {
            if ($hasDoctor) {
        \App\Core\Response::forbidden('Los doctores solo pueden conversar con pacientes');
            }
            if (!$hasPatient) {
        \App\Core\Response::validationError(['participant_user_ids' => 'Debes incluir un paciente asignado']);
            }
            $staffId = $this->getStaffMemberIdForUser($creatorId);
            if (!$staffId) {
        \App\Core\Response::forbidden('No tienes acceso a esta conversacion');
            }
            foreach ($otherIds as $pid) {
        if (($rolesById[$pid] ?? '') !== 'patient') {
            \App\Core\Response::forbidden('Solo puedes conversar con pacientes');
        }
        $patientRow = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? LIMIT 1', [$pid]);
        if (!$patientRow) {
            \App\Core\Response::forbidden('Paciente no encontrado');
        }
        $patientId = intval($patientRow['id']);
        if (!$this->isDoctorAssignedToPatient($creatorId, $patientId)) {
            \App\Core\Response::forbidden('Solo puedes conversar con pacientes asignados');
        }
            }
        }

        $subject = isset($input['subject']) ? trim((string)$input['subject']) : '';
        $firstMessage = isset($input['first_message']) ? trim((string)$input['first_message']) : '';
        if ($firstMessage === '') {
            \App\Core\Response::validationError(['first_message' => 'first_message es requerido']);
        }

        try {
            $db->beginTransaction();

            $db->execute(
        'INSERT INTO conversations (subject, created_by, created_at) VALUES (?, ?, NOW())',
        [$subject !== '' ? $subject : null, $creatorId]
            );
            $convId = intval($db->lastInsertId());

            foreach ($participantIds as $uid) {
        $db->execute(
            'INSERT INTO conversation_participants (conversation_id, user_id, last_read_at, created_at)
             VALUES (?, ?, NULL, NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
            [$convId, $uid]
        );
            }

            $db->execute(
        'INSERT INTO messages (conversation_id, sender_user_id, body, created_at)
         VALUES (?, ?, ?, NOW())',
        [$convId, $creatorId, $firstMessage]
            );

            // mark creator as read
            $db->execute('UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?', [$convId, $creatorId]);

            $db->commit();

            if (class_exists('\\App\\Core\\Audit')) {
        \App\Core\Audit::log('create_conversation', 'conversation', $convId, ['participants' => $participantIds]);
            }

            $conv = $db->fetchOne('SELECT * FROM conversations WHERE id = ? LIMIT 1', [$convId]);
            \App\Core\Response::success($conv, 'Conversación creada', 201);
        } catch (\Exception $e) {
            try { $db->rollback(); } catch (\Throwable $_) {}
            \App\Core\Response::dbException('Error al crear conversación', $e);
        }
    }

    private function listMessages($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);

        try {
            $this->ensureParticipant($id, $userId);

            $items = $db->fetchAll(
        'SELECT m.id, m.conversation_id, m.sender_user_id, m.body, m.created_at,
                u.name as sender_name, u.role as sender_role
         FROM messages m
         JOIN users u ON u.id = m.sender_user_id
         WHERE m.conversation_id = ?
         ORDER BY m.created_at ASC',
        [intval($id)]
            );

            \App\Core\Response::success(['data' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar mensajes', $e);
        }
    }

    private function sendMessage($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);

        $validator = \App\Core\Validator::make($input, [
            'body' => 'required|string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $body = trim((string)$input['body']);
        if ($body === '') {
            \App\Core\Response::validationError(['body' => 'body es requerido']);
        }

        try {
            $this->ensureParticipant($id, $userId);

            $db->execute(
        'INSERT INTO messages (conversation_id, sender_user_id, body, created_at)
         VALUES (?, ?, ?, NOW())',
        [intval($id), $userId, $body]
            );
            $msgId = $db->lastInsertId();

            // mark sender as read
            $db->execute('UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?', [intval($id), $userId]);

            if (class_exists('\\App\\Core\\Audit')) {
        \App\Core\Audit::log('send_message', 'conversation', $id, ['message_id' => $msgId]);
            }

            $msg = $db->fetchOne('SELECT * FROM messages WHERE id = ?', [$msgId]);
            \App\Core\Response::success($msg, 'Mensaje enviado', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al enviar mensaje', $e);
        }
    }

    private function markRead($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);

        try {
            $this->ensureParticipant($id, $userId);
            $db->execute('UPDATE conversation_participants SET last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?', [intval($id), $userId]);
            \App\Core\Response::success(null, 'Marcado como leído');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al marcar leído', $e);
        }
    }
}








