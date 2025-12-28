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

// Buscar usuario
$db = Database::getInstance();
// Crear función para guardar errores en archivo
$logFile = __DIR__ . '/../logs/errors.log';
$append_log = function($msg) use ($logFile) {
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg\n";
    @mkdir(dirname($logFile), 0755, true);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
};

// Buscar usuario
$user = $db->fetchOne(
    'SELECT * FROM users WHERE email = ? LIMIT 1',
    [$email]
);

// Log para depuración (temporal) - no escribir contraseñas en logs
error_log("[LOGIN] attempt for email={$email}");
if (!$user) {
    error_log("[LOGIN] user not found: {$email}");
    Response::error('Credenciales incorrectas', 401);
}

error_log("[LOGIN] user found id={$user['id']} email={$user['email']} role={$user['role']}");

// Guardar hash de contraseña para depuración
$passwordHash = $user['password'] ?? '';
$append_log("User found: id={$user['id']}, email={$user['email']}, has_password=" . (!empty($passwordHash) ? 'yes' : 'no'));

if (!Auth::verifyPassword($password, $passwordHash)) {
    error_log("[LOGIN] password verification FAILED for user id={$user['id']}");
    $append_log("Password verification FAILED for user id={$user['id']}");
    Response::error('Credenciales incorrectas', 401);
}

// Generar token
$token = Auth::generateToken($user['id'], $user['email'], $user['role']);

// Responder directamente con el formato esperado por el frontend
Response::json([
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'email_verified' => isset($user['email_verified']) ? boolval($user['email_verified']) : false
    ]
], 200);
