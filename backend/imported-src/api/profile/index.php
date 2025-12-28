<?php
/**
 * Profile endpoints
 * GET /profile - Get current user profile
 * PUT /profile - Update profile
 * POST /profile/change-password - Change password
 * POST /profile/upload-photo - Upload profile photo
 */

Auth::requireAuth();
$user = Auth::getCurrentUser();
$db = Database::getInstance();

// GET profile
if ($method === 'GET' && !$action) {
    $userData = $db->fetchOne(
        'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
        [$user['user_id']]
    );

    if (!$userData) {
        Response::notFound('Usuario no encontrado');
    }

    $response = ['user' => $userData];

    // Si es paciente, incluir datos del paciente
    if ($userData['role'] === 'patient') {
        $patient = $db->fetchOne(
            'SELECT * FROM patients WHERE user_id = ?',
            [$userData['id']]
        );
        $response['patient'] = $patient;
    }

    // Si es staff, incluir datos adicionales si existen
    if (in_array($userData['role'], ['admin', 'doctor', 'staff'])) {
        // Incluir información desde tabla staff_members si existe
        $staff = $db->fetchOne('SELECT * FROM staff_members WHERE user_id = ?', [$userData['id']]);
        if ($staff) {
            $response['staff_member'] = $staff;
        } else {
            $response['staff_id'] = $userData['id'];
        }
    }

    Response::success($response);
}

// PUT update profile
if ($method === 'PUT' && !$action) {
    $validator = Validator::make($input, [
        'name' => 'string|max:255',
        'email' => 'email|max:255'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    // Verificar si el email ya existe (si se está cambiando)
    if (isset($input['email']) && $input['email'] !== $user['email']) {
        $existing = $db->fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$input['email'], $user['user_id']]);
        if ($existing) {
            Response::error('El email ya está en uso', 422);
        }
    }

    $updates = [];
    $params = [];

    $allowed = ['name', 'email'];
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $input[$field];
        }
    }

    if (empty($updates)) {
        Response::error('No hay datos para actualizar', 400);
    }

    $updates[] = 'updated_at = NOW()';
    $params[] = $user['user_id'];

    try {
        $db->execute(
            'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );

        // Si es paciente, actualizar también tabla patients
        $currentUser = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$user['user_id']]);
        if ($currentUser['role'] === 'patient') {
            $patientUpdates = [];
            $patientParams = [];
            $patientFields = ['name', 'email'];
            
            foreach ($patientFields as $field) {
                if (isset($input[$field])) {
                    $patientUpdates[] = "{$field} = ?";
                    $patientParams[] = $input[$field];
                }
            }
            
            if (!empty($patientUpdates)) {
                $patientUpdates[] = 'updated_at = NOW()';
                $patientParams[] = $user['user_id'];
                $db->execute(
                    'UPDATE patients SET ' . implode(', ', $patientUpdates) . ' WHERE user_id = ?',
                    $patientParams
                );
            }
        }

        if (class_exists('Audit')) {
            Audit::log('update_profile', 'user', $user['user_id'], ['updates' => array_keys($input)]);
        }

        $userData = $db->fetchOne(
            'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
            [$user['user_id']]
        );

        Response::success(['user' => $userData], 'Perfil actualizado');
    } catch (Exception $e) {
        error_log('Update profile error: ' . $e->getMessage());
        Response::error('Error al actualizar perfil');
    }
}

// POST change password
if ($method === 'POST' && $action === 'change-password') {
    $validator = Validator::make($input, [
        'current_password' => 'required|string',
        'new_password' => 'required|string|min:8',
        'confirm_password' => 'required|string'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    if ($input['new_password'] !== $input['confirm_password']) {
        Response::error('Las contraseñas no coinciden', 422);
    }

    // Verificar contraseña actual
    $currentUser = $db->fetchOne('SELECT password FROM users WHERE id = ?', [$user['user_id']]);
    if (!Auth::verifyPassword($input['current_password'], $currentUser['password'])) {
        Response::error('Contraseña actual incorrecta', 401);
    }

    try {
        $newHash = Auth::hashPassword($input['new_password']);
        $db->execute('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [$newHash, $user['user_id']]);

        if (class_exists('Audit')) {
            Audit::log('change_password', 'user', $user['user_id'], []);
        }

        Response::success(null, 'Contraseña actualizada');
    } catch (Exception $e) {
        error_log('Change password error: ' . $e->getMessage());
        Response::error('Error al cambiar contraseña');
    }
}

// POST upload photo
if ($method === 'POST' && $action === 'upload-photo') {
    if (!isset($_FILES['photo'])) {
        Response::error('No se recibió ninguna foto', 400);
    }

    $file = $_FILES['photo'];
    
    // Validar archivo
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowedTypes)) {
        Response::error('Tipo de archivo no permitido. Solo JPG, PNG, GIF', 422);
    }

    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        Response::error('El archivo es muy grande. Máximo 5MB', 422);
    }

    try {
        // Crear directorio si no existe
        $uploadDir = __DIR__ . '/../../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generar nombre único
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . $user['user_id'] . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        // Mover archivo
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Error al subir archivo', 500);
        }

        // Actualizar BD
        $photoUrl = '/uploads/profiles/' . $filename;
        $db->execute('UPDATE users SET photo_url = ?, updated_at = NOW() WHERE id = ?', [$photoUrl, $user['user_id']]);

        if (class_exists('Audit')) {
            Audit::log('upload_profile_photo', 'user', $user['user_id'], ['filename' => $filename]);
        }

        Response::success(['photo_url' => $photoUrl, 'filename' => $filename], 'Foto subida exitosamente');
    } catch (Exception $e) {
        error_log('Upload photo error: ' . $e->getMessage());
        Response::error('Error al subir foto');
    }
}

Response::error('Método no permitido', 405);