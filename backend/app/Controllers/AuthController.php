<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Sanitizer;
use App\Core\Database;
use App\Core\Auth;
use App\Core\Mailer;
use App\Core\Audit;
use App\Core\LoginAttemptTracker;
use App\Core\TwoFactorAuth;

class AuthController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        // autoload of classes is not configured; require minimal files
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Sanitizer.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Mailer.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/LoginAttemptTracker.php';
        require_once __DIR__ . '/../Core/TwoFactorAuth.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function login()
    {
        self::initCore();
        $input = Request::body();

        // Compatibilidad: algunos clientes envían `login` en lugar de `email`
        if (!isset($input['email']) && isset($input['login'])) {
            $input['email'] = $input['login'];
        }

        $validator = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }

        $email = Sanitizer::email((string)($input['email'] ?? ''));
        $password = $input['password'];

        // Logger a archivo: crea `backend/logs/errors.log` si no existe
        $logFile = __DIR__ . '/../../logs/errors.log';
        $appendLog = function (string $msg) use ($logFile): void {
            $time = date('c');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $line = "{$time} [{$ip}] {$msg}\n";
            @mkdir(dirname($logFile), 0755, true);
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        };
        $appendLog("[LOGIN] attempt email={$email}");

        $db = Database::getInstance();
        $attemptTracker = new LoginAttemptTracker($db);
        
        // FASE 2: Verificar si la cuenta está bloqueada
        if ($attemptTracker->isAccountLocked($email)) {
            $lockInfo = $attemptTracker->getLockInfo($email);
            $minutesRemaining = $lockInfo['minutes_remaining'] ?? 15;
            
            $attemptTracker->recordAttempt($email, false, 'account_locked');
            $appendLog("[LOGIN] account_locked email={$email} minutes_remaining={$minutesRemaining}");
            
            Response::error(
                "Cuenta temporalmente bloqueada por múltiples intentos fallidos. " .
                "Intente nuevamente en {$minutesRemaining} minutos.",
                423 // 423 Locked
            );
        }

        $user = $db->fetchOne('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);

        if (!$user) {
            // FASE 2: Registrar intento fallido
            $attemptTracker->recordAttempt($email, false, 'user_not_found');
            $appendLog("[LOGIN] user_not_found email={$email}");
            Response::error('Credenciales incorrectas', 401);
        }

        if (!Auth::verifyPassword($password, $user['password'] ?? '')) {
            // FASE 2: Registrar intento fallido
            $attemptTracker->recordAttempt($email, false, 'invalid_password');
            $appendLog("[LOGIN] invalid_password email={$email} user_id=" . ($user['id'] ?? 'null'));
            
            $remainingAttempts = LoginAttemptTracker::MAX_ATTEMPTS - $attemptTracker->getRecentFailedAttempts($email);
            if ($remainingAttempts > 0 && $remainingAttempts <= 2) {
                Response::error(
                    "Credenciales incorrectas. Le quedan {$remainingAttempts} intentos antes del bloqueo.",
                    401
                );
                $appendLog("[LOGIN] requires_2fa email={$email} user_id=" . ($user['id'] ?? 'null') . " method={$method}");
            }
            
            Response::error('Credenciales incorrectas', 401);
        }

        // FASE 2: Login exitoso - registrar y resetear intentos
        $attemptTracker->recordAttempt($email, true);
        $attemptTracker->resetAttempts($email);

        // Normalizar rol para token y frontend
        $normalizedRole = strtolower(trim((string)($user['role'] ?? '')));
        if ($normalizedRole === 'paciente') {
            $normalizedRole = 'patient';
        }

        // FASE 2: Verificar si tiene 2FA habilitado
        $twoFA = new TwoFactorAuth($db);
        
        if ($twoFA->isEnabled($user['id'])) {
            // Obtener método y destinatario del usuario
            $stmt = $db->prepare("
                SELECT two_factor_method, email, phone 
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            $userInfo = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $method = $userInfo['two_factor_method'] ?? 'email';
            $recipient = ($method === 'email') 
                ? $userInfo['email'] 
                : $userInfo['phone'];
            
            // Generar y enviar código 2FA
            $codeInfo = $twoFA->generateCode($user['id'], $recipient, $method);
            
            if ($codeInfo) {
                // Generar token temporal (válido solo para verificar 2FA)
                $tempToken = Auth::generateToken(
                    $user['id'], 
                    $user['email'], 
                    $normalizedRole,
                    300  // 5 minutos de validez
                );
                
                // Mensaje según método
                $messages = [
                    'email' => 'Se ha enviado un código de verificación a su email',
                    'sms' => 'Se ha enviado un código de verificación por SMS',
                    'whatsapp' => 'Se ha enviado un código de verificación por WhatsApp'
                ];
                
                Response::json([
                    'requires_2fa' => true,
                    'temp_token' => $tempToken,
                    'message' => $messages[$method] ?? $messages['email'],
                    'method' => $method,
                    'expires_in' => TwoFactorAuth::CODE_VALIDITY_MINUTES * 60
                ], 200);
            } else {
                $appendLog("[LOGIN] 2fa_code_generation_failed email={$email} user_id=" . ($user['id'] ?? 'null') . " method={$method}");
                Response::error('Error al generar código de verificación', 500);
            }
        }

        // Login sin 2FA - generar token normal
        $token = Auth::generateToken($user['id'], $user['email'], $normalizedRole);
        $appendLog("[LOGIN] success email={$email} user_id=" . ($user['id'] ?? 'null') . " role={$normalizedRole}");

        Response::json([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'] ?? null,
                'email' => $user['email'],
                'role' => $normalizedRole,
                'email_verified' => isset($user['email_verified']) ? boolval($user['email_verified']) : false
            ]
        ], 200);
    }
    
    /**
     * Verifica código 2FA y completa el login
     */
    public function verify2FA()
    {
        self::initCore();
        $input = Request::body();

        $validator = Validator::make($input, [
            'code' => 'required|string',
            'temp_token' => 'required|string'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }

        $code = trim($input['code']);
        $tempToken = $input['temp_token'];

        // Validar token temporal
        try {
            $payload = Auth::parseToken($tempToken);
            $userId = $payload['user_id'] ?? null;
            
            if (!$userId) {
                Response::error('Token inválido', 401);
            }
        } catch (\Exception $e) {
            Response::error('Token expirado o inválido', 401);
        }

        $db = Database::getInstance();
        $twoFA = new TwoFactorAuth($db);
        
        // Verificar código o backup code
        $verified = $twoFA->verifyCode($userId, $code);
        
        if (!$verified) {
            // Intentar con backup code
            $verified = $twoFA->verifyBackupCode($userId, $code);
        }
        
        if (!$verified) {
            Response::error('Código de verificación inválido o expirado', 401);
        }
        
        // Código verificado - obtener usuario y generar token final
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        
        if (!$user) {
            Response::error('Usuario no encontrado', 404);
        }
        
        // Generar token final (con duración normal)
        $normalizedRole = strtolower(trim((string)($user['role'] ?? '')));
        if ($normalizedRole === 'paciente') {
            $normalizedRole = 'patient';
        }
        $token = Auth::generateToken($user['id'], $user['email'], $normalizedRole);

        Response::json([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'] ?? null,
                'email' => $user['email'],
                'role' => $normalizedRole,
                'email_verified' => isset($user['email_verified']) ? boolval($user['email_verified']) : false
            ]
        ], 200);
    }

    public function register()
    {
        self::initCore();
        $input = Request::body();

        $validator = Validator::make($input, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            Response::validationError(['message' => $e->getMessage()]);
        }

        $db = Database::getInstance();

        $existingUser = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$input['email']]);
        if ($existingUser) {
            Response::error('El email ya está registrado', 422);
        }

        try {
            $db->beginTransaction();

            $db->execute('INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [
                $input['name'],
                $input['email'],
                Auth::hashPassword($input['password']),
                'patient'
            ]);
            $userId = $db->lastInsertId();

            $db->execute('INSERT INTO patients (user_id, name, email, phone, birthday, address, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())', [
                $userId,
                $input['name'],
                $input['email'],
                $input['phone'] ?? null,
                $input['birthday'] ?? null,
                $input['address'] ?? null
            ]);
            $patientId = $db->lastInsertId();

            $db->commit();

            $token = Auth::generateToken($userId, $input['email'], 'patient');

            $verificationToken = bin2hex(random_bytes(16));
            try {
                $db->execute('UPDATE users SET email_verification_token = ?, email_verification_sent_at = NOW(), email_verified = 0 WHERE id = ?', [$verificationToken, $userId]);
            } catch (\Exception $e) {
                error_log('Failed to save verification token: ' . $e->getMessage());
            }

            try {
                $mailer = new Mailer();
                $config = require __DIR__ . '/../../config/app.php';
                $verifyUrl = rtrim($config['app_url'] ?? '', '/') . '/verify-email?token=' . urlencode($verificationToken);
                $subject = 'Verifica tu correo - ' . ($config['app_name'] ?? 'CRM');
                $body = "<p>Hola " . htmlspecialchars($input['name']) . ",</p>" .
                        "<p>Gracias por registrarte. Por favor confirma tu correo haciendo clic en el siguiente enlace:</p>" .
                        "<p><a href=\"{$verifyUrl}\">Verificar correo</a></p>";
                $mailer->send($input['email'], $subject, $body, true);
            } catch (\Exception $e) {
                error_log('Failed sending verification email: ' . $e->getMessage());
            }

            $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patientId]);

            if (class_exists('\App\Core\Audit')) {
                Audit::log('create_user', 'user', $userId, ['email' => $input['email']]);
                Audit::log('create_patient', 'patient', $patientId, ['name' => $input['name']]);
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

        } catch (\Exception $e) {
            $db->rollback();
            Response::dbException('Error al registrar usuario', $e);
        }
    }

    public function me()
    {
        self::initCore();
        Auth::requireAuth();
        $user = Auth::getCurrentUser();
        $db = Database::getInstance();
        $userData = $db->fetchOne('SELECT id, name, email, role, created_at FROM users WHERE id = ?', [$user['user_id']]);
        if (!$userData) {
            Response::notFound('Usuario no encontrado');
        }
        $normalizedRole = strtolower(trim((string)($userData['role'] ?? '')));
        if ($normalizedRole === 'paciente') {
            $normalizedRole = 'patient';
        }
        $userData['role'] = $normalizedRole;

        $response = ['user' => $userData];
        if ($normalizedRole === 'patient') {
            $patient = $db->fetchOne('SELECT * FROM patients WHERE user_id = ?', [$userData['id']]);
            $response['patient'] = $patient ?: [];
        }
        Response::success($response);
    }

    public function logout()
    {
        self::initCore();
        Auth::requireAuth();
        Response::success(null, 'Sesión cerrada exitosamente');
    }

    public function verifyEmail()
    {
        self::initCore();

        try {
            $db = Database::getInstance();
            $input = Request::body();

            $token = $_GET['token'] ?? ($input['token'] ?? null);
            if (empty($token)) {
                Response::validationError(['token' => 'Token requerido']);
            }

            $user = $db->fetchOne('SELECT * FROM users WHERE email_verification_token = ? LIMIT 1', [$token]);
            if (!$user) {
                Response::notFound('Token inválido o expirado');
            }

            $db->execute('UPDATE users SET email_verified = 1, email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?', [$user['id']]);

            if (class_exists('\\App\\Core\\Audit')) {
                Audit::log('verify_email', 'user', $user['id'], ['email' => $user['email']]);
            }

            Response::success(['user_id' => $user['id'], 'email' => $user['email']], 'Email verificado correctamente');
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    public function debugToken()
    {
        self::initCore();

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? null);

        $token = Auth::getTokenFromHeader();
        $user = null;
        $tokenData = null;

        if ($token) {
            $tokenData = Auth::verifyToken($token);
            $user = Auth::validateToken();
        }

        $config = @include __DIR__ . '/../../config/app.php';
        $secretLoaded = is_array($config) && !empty($config['secret_key']);

        Response::json([
            'headers_received' => $headers,
            'auth_header' => $authHeader,
            'token_extracted' => $token,
            'token_parts' => $token ? explode('.', $token) : null,
            'token_valid' => $token ? ($tokenData !== false) : false,
            'token_data' => $tokenData,
            'user' => $user,
            'secret_key_loaded' => $secretLoaded,
        ], 200);
    }
}
