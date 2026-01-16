<?php
namespace App\Controllers;

use App\Core\Request;

class TasksController
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
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin'], 'No tienes permisos para acceder a tareas');

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

        if ($method === 'POST' && $id && $action === 'update-status') {
            return $this->updateStatus($id, $input);
        }

        \App\Core\Response::error('Método no permitido', 405);
    }

    private function getPatientIdForUser($userId)
    {
        $db = \App\Core\Database::getInstance();
        $row = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? LIMIT 1', [$userId]);
        return $row ? intval($row['id']) : null;
    }

    private function canViewTask($task, $user)
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['superadmin', 'admin', 'doctor', 'staff'], true)) {
            return true;
        }

        $userId = intval($user['user_id']);
        if (!empty($task['assigned_to_user_id']) && intval($task['assigned_to_user_id']) === $userId) {
            return true;
        }

        if ($role === 'patient') {
            $pid = $this->getPatientIdForUser($userId);
            return $pid && !empty($task['related_patient_id']) && intval($task['related_patient_id']) === intval($pid);
        }

        return false;
    }

    private function canEditTask($task, $user)
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['superadmin', 'admin'], true)) {
            return true;
        }

        $userId = intval($user['user_id']);
        if (intval($task['created_by']) === $userId) {
            return true;
        }

        if (!empty($task['assigned_to_user_id']) && intval($task['assigned_to_user_id']) === $userId && $role !== 'patient') {
            return true;
        }

        return false;
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');
        $userId = intval($user['user_id']);

        $query = 'SELECT t.*, cu.name as created_by_name, au.name as assigned_to_name, p.name as patient_name
                  FROM tasks t
                  LEFT JOIN users cu ON t.created_by = cu.id
                  LEFT JOIN users au ON t.assigned_to_user_id = au.id
                  LEFT JOIN patients p ON t.related_patient_id = p.id
                  WHERE 1=1';
        $params = [];

        // Visibility
        if ($role === 'patient') {
            $pid = $this->getPatientIdForUser($userId);
            $query .= ' AND (t.related_patient_id = ? OR t.assigned_to_user_id = ?)';
            $params[] = $pid ?: 0;
            $params[] = $userId;
        } elseif (!in_array($role, ['superadmin', 'admin', 'doctor', 'staff'], true)) {
            // Fallback: only assigned
            $query .= ' AND t.assigned_to_user_id = ?';
            $params[] = $userId;
        }

        if (isset($_GET['status'])) {
            $query .= ' AND t.status = ?';
            $params[] = (string)$_GET['status'];
        }

        if (isset($_GET['assigned_to_user_id'])) {
            $query .= ' AND t.assigned_to_user_id = ?';
            $params[] = intval($_GET['assigned_to_user_id']);
        }

        if (isset($_GET['related_patient_id'])) {
            $query .= ' AND t.related_patient_id = ?';
            $params[] = intval($_GET['related_patient_id']);
        }

        $query .= ' ORDER BY t.created_at DESC';

        try {
            $items = $db->fetchAll($query, $params);
            \App\Core\Response::success(['data' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar tareas', $e);
        }
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        try {
            $task = $db->fetchOne(
                'SELECT t.*, cu.name as created_by_name, au.name as assigned_to_name, p.name as patient_name
                 FROM tasks t
                 LEFT JOIN users cu ON t.created_by = cu.id
                 LEFT JOIN users au ON t.assigned_to_user_id = au.id
                 LEFT JOIN patients p ON t.related_patient_id = p.id
                 WHERE t.id = ?
                 LIMIT 1',
                [intval($id)]
            );

            if (!$task) {
                \App\Core\Response::notFound('Tarea no encontrada');
            }

            if (!$this->canViewTask($task, $user)) {
                \App\Core\Response::forbidden('No tienes permisos para ver esta tarea');
            }

            \App\Core\Response::success($task);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al obtener tarea', $e);
        }
    }

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');
        if (!in_array($role, ['superadmin', 'admin', 'doctor', 'staff'], true)) {
            \App\Core\Response::forbidden('No tienes permisos para crear tareas');
        }

        $validator = \App\Core\Validator::make($input, [
            'title' => 'required|string|max:255',
            'description' => 'string',
            'assigned_to_user_id' => 'integer',
            'related_patient_id' => 'integer',
            'status' => 'in:open,in_progress,done,cancelled',
            'priority' => 'in:low,normal,high',
            'due_date' => 'string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();
        try {
            $db->execute(
                'INSERT INTO tasks (created_by, assigned_to_user_id, related_patient_id, title, description, status, priority, due_date, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    intval($user['user_id']),
                    isset($input['assigned_to_user_id']) ? intval($input['assigned_to_user_id']) : null,
                    isset($input['related_patient_id']) ? intval($input['related_patient_id']) : null,
                    (string)$input['title'],
                    $input['description'] ?? null,
                    $input['status'] ?? 'open',
                    $input['priority'] ?? 'normal',
                    $input['due_date'] ?? null
                ]
            );
            $id = $db->lastInsertId();

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_task', 'task', $id, null);
            }

            $task = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$id]);
            \App\Core\Response::success($task, 'Tarea creada', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear tarea', $e);
        }
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        try {
            $existing = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Tarea no encontrada');
            }

            if (!$this->canEditTask($existing, $user)) {
                \App\Core\Response::forbidden('No tienes permisos para editar esta tarea');
            }

            $updates = [];
            $params = [];

            foreach (['title', 'description', 'assigned_to_user_id', 'related_patient_id', 'status', 'priority', 'due_date'] as $field) {
                if (array_key_exists($field, $input)) {
                    $updates[] = $field . ' = ?';
                    $params[] = $input[$field];
                }
            }

            if (empty($updates)) {
                \App\Core\Response::validationError(['message' => 'No hay cambios para aplicar']);
            }

            $updates[] = 'updated_at = NOW()';
            $params[] = intval($id);

            $db->execute('UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_task', 'task', $id, ['fields' => $updates]);
            }

            $task = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [intval($id)]);
            \App\Core\Response::success($task, 'Tarea actualizada');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar tarea', $e);
        }
    }

    private function updateStatus($id, $input)
    {
        $validator = \App\Core\Validator::make($input, [
            'status' => 'required|in:open,in_progress,done,cancelled'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        try {
            $existing = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Tarea no encontrada');
            }

            if (!$this->canViewTask($existing, $user)) {
                \App\Core\Response::forbidden('No tienes permisos para actualizar esta tarea');
            }

            // Only admins/owner/assignee can change status
            $role = (string)($user['role'] ?? '');
            $isAdmin = in_array($role, ['superadmin', 'admin'], true);
            $userId = intval($user['user_id']);
            $isOwner = intval($existing['created_by']) === $userId;
            $isAssignee = !empty($existing['assigned_to_user_id']) && intval($existing['assigned_to_user_id']) === $userId;
            if (!$isAdmin && !$isOwner && !$isAssignee) {
                \App\Core\Response::forbidden('No tienes permisos para cambiar el estado');
            }

            $db->execute('UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?', [(string)$input['status'], intval($id)]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_task_status', 'task', $id, ['status' => $input['status']]);
            }

            $task = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [intval($id)]);
            \App\Core\Response::success($task, 'Estado actualizado');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar estado', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();

        try {
            $existing = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Tarea no encontrada');
            }

            if (!$this->canEditTask($existing, $user)) {
                \App\Core\Response::forbidden('No tienes permisos para eliminar esta tarea');
            }

            $db->execute('DELETE FROM tasks WHERE id = ?', [intval($id)]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_task', 'task', $id, null);
            }

            \App\Core\Response::success(null, 'Tarea eliminada');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar tarea', $e);
        }
    }
}
