<?php
namespace App\Controllers;

use App\Core\Request;

class CommentsController
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
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin'], 'No tienes permisos para acceder a comentarios');

        if ($method === 'GET' && !$id) {
            return $this->index();
        }

        if ($method === 'GET' && $id) {
            return $this->show($id);
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

        \App\Core\Response::error('Método no permitido', 405);
    }

    private function getPatientIdForUser($userId)
    {
        $db = \App\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? LIMIT 1', [$userId]);
        return $row ? intval($row['id']) : null;
    }

    private function canAccessEntity($entityType, $entityId, $user)
    {
        $db = \App\Core\Database::getInstance();
        $role = (string)($user['role'] ?? '');
        $userId = intval($user['user_id']);

        if (in_array($role, ['superadmin', 'admin'], true)) {
            return true;
        }

        if ($entityType === 'task') {
            $task = $db->fetchOne('SELECT * FROM tasks WHERE id = ? LIMIT 1', [intval($entityId)]);
            if (!$task) {
                return false;
            }

            if (in_array($role, ['doctor', 'staff'], true)) {
                return true;
            }

            if (!empty($task['assigned_to_user_id']) && intval($task['assigned_to_user_id']) === $userId) {
                return true;
            }

            if ($role === 'patient') {
                $pid = $this->getPatientIdForUser($userId);
                return $pid && !empty($task['related_patient_id']) && intval($task['related_patient_id']) === $pid;
            }

            return false;
        }

        if ($entityType === 'patient') {
            if (in_array($role, ['doctor', 'staff'], true)) {
                return true;
            }
            if ($role === 'patient') {
                $pid = $this->getPatientIdForUser($userId);
                return $pid && intval($entityId) === $pid;
            }
            return false;
        }

        // Default: deny unknown types
        return false;
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        $entityType = isset($_GET['entity_type']) ? (string)$_GET['entity_type'] : '';
        $entityId = isset($_GET['entity_id']) ? intval($_GET['entity_id']) : 0;

        if ($entityType === '' || $entityId <= 0) {
            \App\Core\Response::validationError(['message' => 'entity_type y entity_id son requeridos']);
        }

        if (!$this->canAccessEntity($entityType, $entityId, $user)) {
            \App\Core\Response::forbidden('No tienes permisos para ver comentarios');
        }

        try {
            $items = $db->fetchAll(
                'SELECT c.*, u.name as author_name
                 FROM comments c
                 LEFT JOIN users u ON c.author_user_id = u.id
                 WHERE c.entity_type = ? AND c.entity_id = ?
                 ORDER BY c.created_at ASC',
                [$entityType, $entityId]
            );
            \App\Core\Response::success(['data' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar comentarios', $e);
        }
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        try {
            $item = $db->fetchOne(
                'SELECT c.*, u.name as author_name
                 FROM comments c
                 LEFT JOIN users u ON c.author_user_id = u.id
                 WHERE c.id = ?
                 LIMIT 1',
                [intval($id)]
            );

            if (!$item) {
                \App\Core\Response::notFound('Comentario no encontrado');
            }

            if (!$this->canAccessEntity((string)$item['entity_type'], intval($item['entity_id']), $user)) {
                \App\Core\Response::forbidden('No tienes permisos para ver este comentario');
            }

            \App\Core\Response::success($item);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al obtener comentario', $e);
        }
    }

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();

        $validator = \App\Core\Validator::make($input, [
            'entity_type' => 'required|string|max:50',
            'entity_id' => 'required|integer',
            'body' => 'required|string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $entityType = (string)$input['entity_type'];
        $entityId = intval($input['entity_id']);
        if (!$this->canAccessEntity($entityType, $entityId, $user)) {
            \App\Core\Response::forbidden('No tienes permisos para comentar aquí');
        }

        $db = \App\Core\Database::getInstance();
        try {
            $db->execute(
                'INSERT INTO comments (entity_type, entity_id, author_user_id, body, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$entityType, $entityId, intval($user['user_id']), (string)$input['body']]
            );
            $id = $db->lastInsertId();

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_comment', 'comment', $id, ['entity_type' => $entityType, 'entity_id' => $entityId]);
            }

            $item = $db->fetchOne('SELECT * FROM comments WHERE id = ?', [$id]);
            \App\Core\Response::success($item, 'Comentario creado', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear comentario', $e);
        }
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');

        $validator = \App\Core\Validator::make($input, [
            'body' => 'required|string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        try {
            $existing = $db->fetchOne('SELECT * FROM comments WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Comentario no encontrado');
            }

            $isAdmin = in_array($role, ['superadmin', 'admin'], true);
            $isOwner = intval($existing['author_user_id']) === intval($user['user_id']);
            if (!$isAdmin && !$isOwner) {
                \App\Core\Response::forbidden('No tienes permisos para editar este comentario');
            }

            if (!$this->canAccessEntity((string)$existing['entity_type'], intval($existing['entity_id']), $user)) {
                \App\Core\Response::forbidden('No tienes permisos para editar comentarios aquí');
            }

            $db->execute('UPDATE comments SET body = ?, updated_at = NOW() WHERE id = ?', [(string)$input['body'], intval($id)]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_comment', 'comment', $id, null);
            }

            $item = $db->fetchOne('SELECT * FROM comments WHERE id = ?', [intval($id)]);
            \App\Core\Response::success($item, 'Comentario actualizado');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar comentario', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');

        try {
            $existing = $db->fetchOne('SELECT * FROM comments WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Comentario no encontrado');
            }

            $isAdmin = in_array($role, ['superadmin', 'admin'], true);
            $isOwner = intval($existing['author_user_id']) === intval($user['user_id']);
            if (!$isAdmin && !$isOwner) {
                \App\Core\Response::forbidden('No tienes permisos para eliminar este comentario');
            }

            if (!$this->canAccessEntity((string)$existing['entity_type'], intval($existing['entity_id']), $user)) {
                \App\Core\Response::forbidden('No tienes permisos para eliminar comentarios aquí');
            }

            $db->execute('DELETE FROM comments WHERE id = ?', [intval($id)]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_comment', 'comment', $id, null);
            }

            \App\Core\Response::success(null, 'Comentario eliminado');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar comentario', $e);
        }
    }
}
