<?php
namespace App\Controllers;

use App\Core\Request;

class UpdatesController
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
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin'], 'No tienes permisos para acceder a actualizaciones');

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

    private function index()
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);
        $role = (string)($user['role'] ?? '');
        $patientId = $role === 'patient' ? $this->getPatientIdForUser($userId) : null;

        // Filters (optional)
        $query = 'SELECT u.id, u.created_by, u.title, u.body, u.audience_type, u.audience_role, u.audience_user_id, u.patient_id, u.created_at, u.updated_at,
                         cu.name as created_by_name
                  FROM updates u
                  LEFT JOIN users cu ON u.created_by = cu.id
                  WHERE 1=1';
        $params = [];

        // Visibility rules
        $query .= ' AND (
            u.audience_type = "all"
            OR (u.audience_type = "role" AND u.audience_role = ?)
            OR (u.audience_type = "user" AND u.audience_user_id = ?)
            OR (u.audience_type = "patient" AND u.patient_id = ?)
        )';
        $params[] = $role;
        $params[] = $userId;
        $params[] = $patientId ?: 0;

        if (isset($_GET['created_by'])) {
            $query .= ' AND u.created_by = ?';
            $params[] = intval($_GET['created_by']);
        }

        if (isset($_GET['patient_id'])) {
            $query .= ' AND u.patient_id = ?';
            $params[] = intval($_GET['patient_id']);
        }

        $query .= ' ORDER BY u.created_at DESC';

        try {
            $items = $db->fetchAll($query, $params);
            \App\Core\Response::success(['data' => $items, 'total' => count($items)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar actualizaciones', $e);
        }
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $userId = intval($user['user_id']);
        $role = (string)($user['role'] ?? '');
        $patientId = $role === 'patient' ? $this->getPatientIdForUser($userId) : null;

        try {
            $item = $db->fetchOne(
                'SELECT u.*, cu.name as created_by_name
                 FROM updates u
                 LEFT JOIN users cu ON u.created_by = cu.id
                 WHERE u.id = ?
                 LIMIT 1',
                [intval($id)]
            );

            if (!$item) {
                \App\Core\Response::notFound('Actualización no encontrada');
            }

            $visible = (
                $item['audience_type'] === 'all'
                || ($item['audience_type'] === 'role' && $item['audience_role'] === $role)
                || ($item['audience_type'] === 'user' && intval($item['audience_user_id'] ?? 0) === $userId)
                || ($item['audience_type'] === 'patient' && $patientId && intval($item['patient_id'] ?? 0) === $patientId)
            );

            if (!$visible) {
                \App\Core\Response::forbidden('No tienes permisos para ver esta actualización');
            }

            \App\Core\Response::success($item);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al obtener actualización', $e);
        }
    }

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');
        if (!in_array($role, ['superadmin', 'admin', 'doctor', 'staff'], true)) {
            \App\Core\Response::forbidden('No tienes permisos para crear actualizaciones');
        }

        $validator = \App\Core\Validator::make($input, [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'audience_type' => 'in:all,role,user,patient',
            'audience_role' => 'in:superadmin,admin,doctor,staff,patient',
            'audience_user_id' => 'integer',
            'patient_id' => 'integer'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $audienceType = $input['audience_type'] ?? 'all';
        $audienceRole = $input['audience_role'] ?? null;
        $audienceUserId = isset($input['audience_user_id']) ? intval($input['audience_user_id']) : null;
        $patientId = isset($input['patient_id']) ? intval($input['patient_id']) : null;

        // Normalize invalid combos
        if ($audienceType === 'role' && !$audienceRole) {
            \App\Core\Response::validationError(['audience_role' => 'audience_role es requerido cuando audience_type=role']);
        }
        if ($audienceType === 'user' && !$audienceUserId) {
            \App\Core\Response::validationError(['audience_user_id' => 'audience_user_id es requerido cuando audience_type=user']);
        }
        if ($audienceType === 'patient' && !$patientId) {
            \App\Core\Response::validationError(['patient_id' => 'patient_id es requerido cuando audience_type=patient']);
        }

        $db = \App\Core\Database::getInstance();

        try {
            $db->execute(
                'INSERT INTO updates (created_by, title, body, audience_type, audience_role, audience_user_id, patient_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    intval($user['user_id']),
                    (string)$input['title'],
                    (string)$input['body'],
                    (string)$audienceType,
                    $audienceRole,
                    $audienceUserId,
                    $patientId
                ]
            );
            $id = $db->lastInsertId();

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_update', 'update', $id, ['audience_type' => $audienceType]);
            }

            $item = $db->fetchOne('SELECT * FROM updates WHERE id = ?', [$id]);
            \App\Core\Response::success($item, 'Actualización creada', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear actualización', $e);
        }
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');

        try {
            $existing = $db->fetchOne('SELECT * FROM updates WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Actualización no encontrada');
            }

            $isAdmin = in_array($role, ['superadmin', 'admin'], true);
            $isOwner = intval($existing['created_by']) === intval($user['user_id']);
            if (!$isAdmin && !$isOwner) {
                \App\Core\Response::forbidden('No tienes permisos para editar esta actualización');
            }

            $updates = [];
            $params = [];

            foreach (['title', 'body', 'audience_type', 'audience_role', 'audience_user_id', 'patient_id'] as $field) {
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

            $db->execute('UPDATE updates SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_update', 'update', $id, ['fields' => $updates]);
            }

            $item = $db->fetchOne('SELECT * FROM updates WHERE id = ?', [intval($id)]);
            \App\Core\Response::success($item, 'Actualización actualizada');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar actualización', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');

        try {
            $existing = $db->fetchOne('SELECT * FROM updates WHERE id = ?', [intval($id)]);
            if (!$existing) {
                \App\Core\Response::notFound('Actualización no encontrada');
            }

            $isAdmin = in_array($role, ['superadmin', 'admin'], true);
            $isOwner = intval($existing['created_by']) === intval($user['user_id']);
            if (!$isAdmin && !$isOwner) {
                \App\Core\Response::forbidden('No tienes permisos para eliminar esta actualización');
            }

            $db->execute('DELETE FROM updates WHERE id = ?', [intval($id)]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_update', 'update', $id, null);
            }

            \App\Core\Response::success(null, 'Actualización eliminada');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar actualización', $e);
        }
    }
}
