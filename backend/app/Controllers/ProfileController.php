<?php
namespace App\Controllers;

use App\Core\Request;

class ProfileController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        try {
            \App\Core\Auth::requireAuth();
            $user = \App\Core\Auth::getCurrentUser();

            if ($method === 'GET' && !$action) {
                return $this->show($user);
            }

            if ($method === 'PUT' && !$action) {
                return $this->update($user, $input);
            }

            if ($method === 'POST' && $action === 'change-password') {
                return $this->changePassword($user, $input);
            }

            if ($method === 'POST' && $action === 'upload-photo') {
                return $this->uploadPhoto($user);
            }

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function show($user)
    {
        $db = \App\Core\Database::getInstance();
        $userData = $db->fetchOne(
            'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
            [$user['user_id']]
        );

        if (!$userData) {
            \App\Core\Response::notFound('Usuario no encontrado');
        }

        $response = ['user' => $userData];

        if (($userData['role'] ?? null) === 'patient') {
            $patient = $db->fetchOne('SELECT * FROM patients WHERE user_id = ?', [$userData['id']]);
            $response['patient'] = $patient;
        }

        if (in_array($userData['role'], ['admin', 'doctor', 'staff'])) {
            $staff = $db->fetchOne('SELECT * FROM staff_members WHERE user_id = ?', [$userData['id']]);
            if ($staff) {
                $response['staff_member'] = $staff;
            } else {
                $response['staff_id'] = $userData['id'];
            }
        }

        \App\Core\Response::success($response);
    }

    private function update($user, $input)
    {
        $db = \App\Core\Database::getInstance();

        $validator = \App\Core\Validator::make($input, [
            'name' => 'string|max:255',
            'email' => 'email|max:255',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        if (isset($input['email']) && $input['email'] !== ($user['email'] ?? null)) {
            $existing = $db->fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$input['email'], $user['user_id']]);
            if ($existing) {
                \App\Core\Response::error('El email ya está en uso', 422);
            }
        }

        $updates = [];
        $params = [];
        foreach (['name', 'email'] as $field) {
            if (isset($input[$field])) {
                $updates[] = $field . ' = ?';
                $params[] = $input[$field];
            }
        }

        if (empty($updates)) {
            \App\Core\Response::error('No hay datos para actualizar', 400);
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $user['user_id'];

        try {
            $db->execute('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

            $currentUser = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$user['user_id']]);
            if (($currentUser['role'] ?? null) === 'patient') {
                $patientUpdates = [];
                $patientParams = [];
                foreach (['name', 'email'] as $field) {
                    if (isset($input[$field])) {
                        $patientUpdates[] = $field . ' = ?';
                        $patientParams[] = $input[$field];
                    }
                }
                if (!empty($patientUpdates)) {
                    $patientUpdates[] = 'updated_at = NOW()';
                    $patientParams[] = $user['user_id'];
                    $db->execute('UPDATE patients SET ' . implode(', ', $patientUpdates) . ' WHERE user_id = ?', $patientParams);
                }
            }

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_profile', 'user', $user['user_id'], ['updates' => array_keys($input)]);
            }

            $userData = $db->fetchOne(
                'SELECT id, name, email, role, created_at FROM users WHERE id = ?',
                [$user['user_id']]
            );
            \App\Core\Response::success(['user' => $userData], 'Perfil actualizado');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar perfil', $e);
        }
    }

    private function changePassword($user, $input)
    {
        $db = \App\Core\Database::getInstance();

        $validator = \App\Core\Validator::make($input, [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
            'confirm_password' => 'required|string',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        if (($input['new_password'] ?? null) !== ($input['confirm_password'] ?? null)) {
            \App\Core\Response::error('Las contraseñas no coinciden', 422);
        }

        $currentUser = $db->fetchOne('SELECT password FROM users WHERE id = ?', [$user['user_id']]);
        if (!$currentUser || !\App\Core\Auth::verifyPassword($input['current_password'], $currentUser['password'])) {
            \App\Core\Response::error('Contraseña actual incorrecta', 401);
        }

        try {
            $newHash = \App\Core\Auth::hashPassword($input['new_password']);
            $db->execute('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?', [$newHash, $user['user_id']]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('change_password', 'user', $user['user_id'], []);
            }

            \App\Core\Response::success(null, 'Contraseña actualizada');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al cambiar contraseña', $e);
        }
    }

    private function uploadPhoto($user)
    {
        $db = \App\Core\Database::getInstance();

        if (!isset($_FILES['photo'])) {
            \App\Core\Response::error('No se recibió ninguna foto', 400);
        }

        $file = $_FILES['photo'];

        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowedTypes)) {
            \App\Core\Response::error('Tipo de archivo no permitido. Solo JPG, PNG, GIF', 422);
        }

        $maxSize = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            \App\Core\Response::error('El archivo es muy grande. Máximo 5MB', 422);
        }

        try {
            $uploadDir = __DIR__ . '/../../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $user['user_id'] . '_' . time() . '.' . $extension;
            $filepath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                \App\Core\Response::error('Error al subir archivo', 500);
            }

            $photoUrl = '/uploads/profiles/' . $filename;
            $db->execute('UPDATE users SET photo_url = ?, updated_at = NOW() WHERE id = ?', [$photoUrl, $user['user_id']]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('upload_profile_photo', 'user', $user['user_id'], ['filename' => $filename]);
            }

            \App\Core\Response::success(['photo_url' => $photoUrl, 'filename' => $filename], 'Foto subida exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::exception('Error al subir foto', $e);
        }
    }
}
