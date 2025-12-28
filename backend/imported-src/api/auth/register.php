<?php
/**
 * Endpoint de Registro de Paciente
 */

// Validar datos
$validator = Validator::make($input, [
    'name' => 'required|string|max:255',
    'email' => 'required|email|max:255',
    'password' => 'required|string|min:8',
    'phone' => 'string|max:20',
    'birthday' => 'date',
    'address' => 'string|max:500'
]);

if (!$validator->validate()) {
    Response::validationError($validator->getErrors());
}

$db = Database::getInstance();

// Verificar si el email ya existe
$existingUser = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$input['email']]);
if ($existingUser) {
    Response::error('El email ya está registrado', 422);
}

try {
    $db->beginTransaction();

    // Crear usuario
    $userId = $db->execute(
        'INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
        [
            $input['name'],
            $input['email'],
            Auth::hashPassword($input['password']),
            'patient'
        ]
    );
    $userId = $db->lastInsertId();

    // Crear paciente
    $patientId = $db->execute(
        'INSERT INTO patients (user_id, name, email, phone, birthday, address, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
        [
            $userId,
            $input['name'],
            $input['email'],
            $input['phone'] ?? null,
            $input['birthday'] ?? null,
            $input['address'] ?? null
        ]
    );
    $patientId = $db->lastInsertId();

    $db->commit();

    // Generar token
    $token = Auth::generateToken($userId, $input['email'], 'patient');

    // Email verification token
    $verificationToken = bin2hex(random_bytes(16));
    $config = require __DIR__ . '/../../config/app.php';
    try {
        $db->execute('UPDATE users SET email_verification_token = ?, email_verification_sent_at = NOW(), email_verified = 0 WHERE id = ?', [$verificationToken, $userId]);
    } catch (Exception $e) {
        error_log('Failed to save verification token: ' . $e->getMessage());
    }

    // Send verification email (best effort)
    try {
        require_once __DIR__ . '/../../core/Mailer.php';
        $mailer = new Mailer();
        $verifyUrl = rtrim($config['app_url'], '/') . '/crm/backend/public/verify-email?token=' . urlencode($verificationToken);
        $subject = 'Verifica tu correo - ' . ($config['app_name'] ?? 'CRM');
        $body = "<p>Hola " . htmlspecialchars($input['name']) . ",</p>" .
                "<p>Gracias por registrarte. Por favor confirma tu correo haciendo clic en el siguiente enlace:</p>" .
                "<p><a href=\"{$verifyUrl}\">Verificar correo</a></p>" .
                "<p>Si no solicitaste este correo, ignora este mensaje.</p>";
        $mailer->send($input['email'], $subject, $body, true);
    } catch (Exception $e) {
        error_log('Failed sending verification email: ' . $e->getMessage());
    }

    // Recuperar paciente completo para la respuesta
    $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patientId]);

    // Audit: registro de nuevo usuario/paciente (self-registration)
    if (class_exists('Audit')) {
        Audit::log('create_user', 'user', $userId, [
            'created_by' => Auth::getCurrentUser()['user_id'] ?? null,
            'email' => $input['email']
        ]);

        Audit::log('create_patient', 'patient', $patientId, [
            'created_by' => Auth::getCurrentUser()['user_id'] ?? null,
            'name' => $input['name']
        ]);
    }

    Response::success([
        'token' => $token,
        'user' => [
            'id' => $userId,
            'name' => $input['name'],
            'email' => $input['email'],
            'role' => 'patient'
        ],
        'patient' => $patient
    ], 'Registro exitoso', 201);

} catch (Exception $e) {
    $db->rollback();
    error_log('Register error: ' . $e->getMessage());
    Response::error('Error al registrar usuario');
}
