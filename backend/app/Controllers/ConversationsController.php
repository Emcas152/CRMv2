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
            \App\Core\Response::forbidden('No tienes acceso a esta conversación');
        }
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

        // Patients: only conversations with doctors/staff/admin from their appointments
        if ($role === 'patient') {
            $sql .= ' AND c.id IN (
                        SELECT DISTINCT cp.conversation_id
                        FROM conversation_participants cp
                        JOIN users u ON u.id = cp.user_id
                        WHERE u.role IN ("doctor", "staff", "admin", "superadmin")
                          AND cp.conversation_id IN (
                            SELECT DISTINCT c2.id
                            FROM conversations c2
                            JOIN conversation_participants cp2 ON cp2.conversation_id = c2.id AND cp2.user_id = ?
                          )
                      )';
            $params[] = $userId;
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

            // Patients: ensure they can only view conversations with ops staff
            if ($role === 'patient') {
                $hasOpsParticipant = $db->fetchOne(
                    'SELECT 1 FROM conversation_participants cp
                     JOIN users u ON u.id = cp.user_id
                     WHERE cp.conversation_id = ? AND u.role IN ("doctor", "staff", "admin", "superadmin") AND u.id != ?
                     LIMIT 1',
                    [intval($id), $userId]
                );
                if (!$hasOpsParticipant) {
                    \App\Core\Response::forbidden('No tienes acceso a esta conversación');
                }
            }

            $conv = $db->fetchOne('SELECT * FROM conversations WHERE id = ? LIMIT 1', [intval($id)]);
            if (!$conv) {
                \App\Core\Response::notFound('Conversación no encontrada');
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
            \App\Core\Response::dbException('Error al obtener conversación', $e);
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

        // Rule: patients cannot start conversations with other patients.
        $creatorRole = (string)($user['role'] ?? '');
        if ($creatorRole === 'patient') {
            $rows = $db->fetchAll('SELECT id, role FROM users WHERE id IN (' . implode(',', array_fill(0, count($participantIds), '?')) . ')', $participantIds);
            $rolesById = [];
            foreach ($rows as $r) {
                $rolesById[intval($r['id'])] = (string)$r['role'];
            }
            foreach ($participantIds as $pid) {
                if ($pid === $creatorId) {
                    continue;
                }
                if (($rolesById[$pid] ?? 'patient') === 'patient') {
                    \App\Core\Response::forbidden('Los pacientes no pueden iniciar conversaciones con otros pacientes');
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
