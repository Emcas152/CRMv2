<?php
/**
 * API de Usuarios
 * Gestión de usuarios del sistema - Solo superadmin y admin
 */

// Verificar autenticación
$authUser = Auth::getCurrentUser();

// Debug temporal
error_log('DEBUG /users endpoint:');
error_log('Auth header: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT SET'));
error_log('Token from header: ' . (Auth::getTokenFromHeader() ?? 'NOT FOUND'));
error_log('All headers: ' . json_encode(getallheaders()));
error_log('Auth user: ' . ($authUser ? json_encode($authUser) : 'NULL/FALSE'));
error_log('Auth user type: ' . gettype($authUser));

if (!$authUser || !is_array($authUser)) {
    Response::unauthorized('Token inválido o expirado');
}

// Solo superadmin y admin pueden gestionar usuarios
if (!in_array($authUser['role'], ['superadmin', 'admin'])) {
    error_log('User role: ' . $authUser['role'] . ' - Access denied');
    Response::forbidden('No tienes permisos para gestionar usuarios');
}

$db = Database::getInstance();

// GET /users - Listar usuarios
if ($method === 'GET' && !$id) {
    $role = $_GET['role'] ?? null;
    $search = $_GET['search'] ?? null;

    $query = "SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE 1=1";
    $params = [];

    if ($role) {
        $query .= " AND role = ?";
        $params[] = $role;
    }

    if ($search) {
        $query .= " AND (name LIKE ? OR email LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $query .= " ORDER BY created_at DESC";

    $users = $db->fetchAll($query, $params);

    Response::success($users);
}

// GET /users/:id - Obtener un usuario
if ($method === 'GET' && $id) {
    $user = $db->fetchOne(
        "SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE id = ?",
        [$id]
    );

    if (!$user) {
        Response::notFound('Usuario no encontrado');
    }

    Response::success($user);
}

// POST /users - Crear usuario
if ($method === 'POST' && !$id) {
    $validator = Validator::make($input, [
        'name' => 'required|string|min:3',
        'email' => 'required|email',
        'password' => 'required|string|min:6',
        'role' => 'required|in:superadmin,admin,doctor,staff,patient',
        'phone' => 'string'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    // Solo superadmin puede crear otros superadmins
    if ($input['role'] === 'superadmin' && $authUser['role'] !== 'superadmin') {
        Response::forbidden('Solo superadmin puede crear otros superadmins');
    }

    // Verificar si el email ya existe
    $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$input['email']]);
    if ($existing) {
        Response::error('El email ya está registrado', 422);
    }

    // Hash de contraseña
    $passwordHash = Auth::hashPassword($input['password']);

    try {
        // Crear usuario
        $db->execute(
            'INSERT INTO users (name, email, password, role, phone, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                $input['name'],
                $input['email'],
                $passwordHash,
                $input['role'],
                $input['phone'] ?? null
            ]
        );

        $userId = $db->lastInsertId();
        $newUser = $db->fetchOne(
            "SELECT id, name, email, role, phone, active, created_at FROM users WHERE id = ?",
            [$userId]
        );

        Response::success($newUser, 'Usuario creado exitosamente', 201);
    } catch (Exception $e) {
        error_log('Create user error: ' . $e->getMessage());
        Response::error('Error al crear usuario');
    }
}

// PUT /users/:id - Actualizar usuario
if ($method === 'PUT' && $id) {
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);

    if (!$user) {
        Response::notFound('Usuario no encontrado');
    }

    $validator = Validator::make($input, [
        'name' => 'string|min:3',
        'email' => 'email',
        'role' => 'in:superadmin,admin,doctor,staff,patient',
        'phone' => 'string',
        'active' => 'boolean'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    // Solo superadmin puede modificar roles de superadmin
    if (($user['role'] === 'superadmin' || ($input['role'] ?? '') === 'superadmin') && $authUser['role'] !== 'superadmin') {
        Response::forbidden('Solo superadmin puede modificar superadmins');
    }

    // Verificar email único si se está cambiando
    if (isset($input['email']) && $input['email'] !== $user['email']) {
        $existing = $db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$input['email'], $id]);
        if ($existing) {
            Response::error('El email ya está registrado', 422);
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

        // Si se proporciona nueva contraseña
        if (!empty($input['password'])) {
            $updates[] = 'password = ?';
            $params[] = Auth::hashPassword($input['password']);
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $query = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->execute($query, $params);

        $updatedUser = $db->fetchOne(
            "SELECT id, name, email, role, phone, active, created_at, updated_at FROM users WHERE id = ?",
            [$id]
        );

        Response::success($updatedUser, 'Usuario actualizado exitosamente');
    } catch (Exception $e) {
        error_log('Update user error: ' . $e->getMessage());
        Response::error('Error al actualizar usuario');
    }
}

// DELETE /users/:id - Eliminar usuario
if ($method === 'DELETE' && $id) {
    $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);

    if (!$user) {
        Response::notFound('Usuario no encontrado');
    }

    // No se puede eliminar a sí mismo
    if ($user['id'] == $authUser['id']) {
        Response::error('No puedes eliminar tu propio usuario', 400);
    }

    // Solo superadmin puede eliminar superadmins
    if ($user['role'] === 'superadmin' && $authUser['role'] !== 'superadmin') {
        Response::forbidden('Solo superadmin puede eliminar superadmins');
    }

    try {
        $db->execute('DELETE FROM users WHERE id = ?', [$id]);
        Response::success(null, 'Usuario eliminado exitosamente');
    } catch (Exception $e) {
        error_log('Delete user error: ' . $e->getMessage());
        Response::error('Error al eliminar usuario');
    }
}

Response::error('Método no permitido', 405);
