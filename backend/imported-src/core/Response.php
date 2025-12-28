<?php
/**
 * Clase de Respuestas JSON
 */

class Response
{
    public static function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);

        // Encode payload first so we can set an accurate Content-Length
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Ensure previous output buffers (if any) are cleared to avoid corrupting JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($payload));

        echo $payload;
        // flush to the client and terminate
        if (function_exists('fastcgi_finish_request')) {
            // If running under FastCGI, finish the request so PHP can continue background tasks
            fastcgi_finish_request();
        }
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

        // Ensure we don't leak sensitive internal structures: if errors is Exception-like, stringify minimally
        if (isset($response['errors']) && is_object($response['errors'])) {
            $response['errors'] = ['detail' => (string)$response['errors']];
        }

        self::json($response, $statusCode);
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
