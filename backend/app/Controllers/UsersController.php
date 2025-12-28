<?php
namespace App\Controllers;

use App\Core\Request;

class UsersController
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
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        try {
            $authUser = \App\Core\Auth::getCurrentUser();
            if (!$authUser || !is_array($authUser)) {
                \App\Core\Response::unauthorized('Token inválido o expirado');
            }

            if (!in_array($authUser['role'], ['superadmin', 'admin'])) {
                \App\Core\Response::forbidden('No tienes permisos para gestionar usuarios');
            }

            if ($method === 'GET' && !$id) {
                return $this->index();
            }

            if ($method === 'GET' && $id) {
                return $this->show($id);
            }

            if ($method === 'POST' && !$id) {
                return $this->store($input, $authUser);
            }

            if ($method === 'PUT' && $id) {
                return $this->update($id, $input, $authUser);
            }

            if ($method === 'DELETE' && $id) {
                return $this->destroy($id, $authUser);
            }

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();

        $role = $_GET['role'] ?? null;
        $search = $_GET['search'] ?? null;

        $query = 'SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE 1=1';
        $params = [];

        if ($role) {
            $query .= ' AND role = ?';
            $params[] = $role;
        }

        if ($search) {
            $query .= ' AND (name LIKE ? OR email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $query .= ' ORDER BY created_at DESC';

        $users = $db->fetchAll($query, $params);
        \App\Core\Response::success($users);
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();

        $user = $db->fetchOne(
            'SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE id = ?',
            [$id]
        );

        if (!$user) {
            \App\Core\Response::notFound('Usuario no encontrado');
        }

        \App\Core\Response::success($user);
    }

    private function store($input, $authUser)
    {
        $validator = \App\Core\Validator::make($input, [
            'name' => 'required|string|min:3',
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:superadmin,admin,doctor,staff,patient',
            'phone' => 'string',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        if (($input['role'] ?? null) === 'superadmin' && ($authUser['role'] ?? null) !== 'superadmin') {
            \App\Core\Response::forbidden('Solo superadmin puede crear otros superadmins');
        }

        $db = \App\Core\Database::getInstance();

        $existing = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$input['email']]);
        if ($existing) {
            \App\Core\Response::error('El email ya está registrado', 422);
        }

        try {
            $passwordHash = \App\Core\Auth::hashPassword($input['password']);

            $db->execute(
                'INSERT INTO users (name, email, password, role, phone, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [$input['name'], $input['email'], $passwordHash, $input['role'], $input['phone'] ?? null]
            );

            $userId = $db->lastInsertId();
            $newUser = $db->fetchOne(
                'SELECT id, name, email, role, phone, active, created_at FROM users WHERE id = ?',
                [$userId]
            );

            \App\Core\Response::success($newUser, 'Usuario creado exitosamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear usuario', $e);
        }
    }

    private function update($id, $input, $authUser)
    {
        $db = \App\Core\Database::getInstance();

        $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            \App\Core\Response::notFound('Usuario no encontrado');
        }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'string|min:3',
            'email' => 'email',
            'role' => 'in:superadmin,admin,doctor,staff,patient',
            'phone' => 'string',
            'active' => 'boolean',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        if ((($user['role'] ?? null) === 'superadmin' || (($input['role'] ?? '') === 'superadmin')) && ($authUser['role'] ?? null) !== 'superadmin') {
            \App\Core\Response::forbidden('Solo superadmin puede modificar superadmins');
        }

        if (isset($input['email']) && $input['email'] !== ($user['email'] ?? null)) {
            $existing = $db->fetchOne('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1', [$input['email'], $id]);
            if ($existing) {
                \App\Core\Response::error('El email ya está registrado', 422);
            }
        }

        try {
            $updates = [];
            $params = [];

            if (isset($input['name'])) {
                $updates[] = 'name = ?';
                $params[] = $input['name'];
            }
            if (isset($input['email'])) {
                $updates[] = 'email = ?';
                $params[] = $input['email'];
            }
            if (isset($input['role'])) {
                $updates[] = 'role = ?';
                $params[] = $input['role'];
            }
            if (isset($input['phone'])) {
                $updates[] = 'phone = ?';
                $params[] = $input['phone'];
            }
            if (isset($input['active'])) {
                $updates[] = 'active = ?';
                $params[] = $input['active'] ? 1 : 0;
            }

            if (!empty($input['password'])) {
                $updates[] = 'password = ?';
                $params[] = \App\Core\Auth::hashPassword($input['password']);
            }

            if (empty($updates)) {
                $updatedUser = $db->fetchOne(
                    'SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE id = ?',
                    [$id]
                );
                \App\Core\Response::success($updatedUser, 'Sin cambios');
            }

            $updates[] = 'updated_at = NOW()';
            $params[] = $id;

            $query = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->execute($query, $params);

            $updatedUser = $db->fetchOne(
                'SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE id = ?',
                [$id]
            );

            \App\Core\Response::success($updatedUser, 'Usuario actualizado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar usuario', $e);
        }
    }

    private function destroy($id, $authUser)
    {
        $db = \App\Core\Database::getInstance();
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);

        if (!$user) {
            \App\Core\Response::notFound('Usuario no encontrado');
        }

        if (($user['id'] ?? null) == ($authUser['user_id'] ?? null)) {
            \App\Core\Response::error('No puedes eliminar tu propio usuario', 400);
        }

        if (($user['role'] ?? null) === 'superadmin' && ($authUser['role'] ?? null) !== 'superadmin') {
            \App\Core\Response::forbidden('Solo superadmin puede eliminar superadmins');
        }

        try {
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
            \App\Core\Response::success(null, 'Usuario eliminado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar usuario', $e);
        }
    }
}
