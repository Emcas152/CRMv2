<?php
/**
 * Endpoint de Login
 */

// Validar datos
$validator = Validator::make($input, [
    'email' => 'required|email',
    'password' => 'required|string|min:6'
]);

if (!$validator->validate()) {
    Response::validationError($validator->getErrors());
}

$email = Sanitizer::email($input['email']);
$password = $input['password'];

$db = Database::getInstance();

// Logger seguro
$logFile = __DIR__ . '/../logs/errors.log';
$append_log = function($msg) use ($logFile) {
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg\n";
    @mkdir(dirname($logFile), 0755, true);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

// Buscar usuario
$user = $db->fetchOne(
    'SELECT id, name, email, password, role, email_verified FROM users WHERE email = ? LIMIT 1',
    [$email]
);

error_log("[LOGIN] attempt for email={$email}");

if (!$user) {
    error_log("[LOGIN] user not found: {$email}");
    $append_log("Login failed: user not found for email={$email}");
    Response::error('Credenciales incorrectas', 401);
}

// Log user found (sin password)
error_log("[LOGIN] user found id={$user['id']} email={$user['email']} role={$user['role']}");
$append_log("User found: id={$user['id']}, email={$user['email']}, role={$user['role']}");

// Verificar contraseña
$passwordHash = $user['password'] ?? '';

if (!Auth::verifyPassword($password, $passwordHash)) {
    error_log("[LOGIN] password verification FAILED for user id={$user['id']}");
    $append_log("Password verification FAILED for user id={$user['id']}");
    Response::error('Credenciales incorrectas', 401);
}

// Normalizar rol
$normalizedRole = strtolower(trim($user['role'] ?? ''));

// Generar token
$token = Auth::generateToken(
    $user['id'],
    $user['email'],
    $normalizedRole
);

// Respuesta estándar al frontend
Response::json([
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $normalizedRole,
        'email_verified' => isset($user['email_verified']) ? boolval($user['email_verified']) : false
    ]
], 200);
