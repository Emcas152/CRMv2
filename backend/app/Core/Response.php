<?php
namespace App\Core;

class Response
{
    private static function shouldIncludeDebug()
    {
        $env = getenv('APP_ENV') ?: 'local';
        return $env !== 'production';
    }

    private static function shouldExposeExceptionMessage()
    {
        if (self::shouldIncludeDebug()) {
            return true;
        }

        $flag = getenv('APP_SHOW_EXCEPTION_MESSAGES');
        return filter_var($flag ?: false, FILTER_VALIDATE_BOOLEAN);
    }

    private static function withDebugErrors($errors, $e)
    {
        $out = is_array($errors) ? $errors : [];

        if (self::shouldIncludeDebug() && $e) {
            $out['debug'] = [
                'type' => is_object($e) ? get_class($e) : null,
                'message' => is_object($e) ? $e->getMessage() : null,
                'code' => is_object($e) ? $e->getCode() : null,
            ];
        }

        return empty($out) ? null : $out;
    }

    public static function json($data, $statusCode = 200)
    {
        // Ensure UTF-8 content type is sent for all JSON responses
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        // Ensure PHP internal encoding uses UTF-8
        if (function_exists('ini_set')) {
            @ini_set('default_charset', 'utf-8');
        }

        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, $message = 'Operación exitosa', $statusCode = 200)
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error($message = 'Error en la operación', $statusCode = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        self::json($response, $statusCode);
    }

    public static function conflict($message = 'Conflicto', $errors = null)
    {
        self::error($message, 409, $errors);
    }

    public static function exception($message, $e, $statusCode = 500, $errors = null)
    {
        try {
            $prefix = (string)($message ?: 'Unhandled exception');
            $detail = is_object($e) ? (get_class($e) . ': ' . $e->getMessage()) : 'unknown';
            error_log($prefix . ' | ' . $detail);
        } catch (\Throwable $_) {
            // never block response
        }

        $outMessage = (string)($message ?: 'Error interno');
        if (self::shouldExposeExceptionMessage() && is_object($e)) {
            $exMsg = trim((string)$e->getMessage());
            if ($exMsg !== '') {
                $outMessage .= ': ' . $exMsg;
            }
        }

        $errs = self::withDebugErrors($errors, $e);
        self::error($outMessage, intval($statusCode ?: 500), $errs);
    }

    public static function dbException($message, $e, $errors = null)
    {
        $status = 500;
        if ($e instanceof \PDOException) {
            // SQLSTATE 23000: integrity constraint violation (FK, unique, etc.)
            if ((string)$e->getCode() === '23000') {
                $status = 409;
            }
        }
        self::exception($message ?: 'Error en base de datos', $e, $status, $errors);
    }

    public static function unauthorized($message = 'No autorizado')
    {
        self::error($message, 401);
    }

    public static function forbidden($message = 'Acceso denegado')
    {
        self::error($message, 403);
    }

    public static function notFound($message = 'Recurso no encontrado')
    {
        self::error($message, 404);
    }

    public static function validationError($errors, $message = 'Error de validación')
    {
        self::error($message, 422, $errors);
    }
}

