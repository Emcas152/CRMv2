<?php
/**
 * Manejador global de errores/excepciones
 */
class ErrorHandler
{
    public static function handle(Throwable $e)
    {
        $errorId = uniqid('err_', true);

        // Formatea mensaje para log (evitar exponer datos sensibles)
        $message = sprintf("[%s] %s in %s on line %s\nStack trace:\n%s", $errorId, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

        // Log to PHP error log (configured in public/index.php)
        error_log($message);

        // Additional: write to application log file if configured
        if (defined('APP_LOG_FILE') && APP_LOG_FILE) {
            @file_put_contents(APP_LOG_FILE, date('c') . " " . $message . "\n", FILE_APPEND | LOCK_EX);
        }

        // If in development, include stack trace in response; otherwise return generic message with error id
        $config = @include __DIR__ . '/../config/app.php';
        $env = $config['app_env'] ?? getenv('APP_ENV') ?: 'production';

        if ($env !== 'production') {
            $detail = ['error_id' => $errorId, 'exception' => $e->getMessage(), 'trace' => $e->getTrace()];
            Response::error('Error interno del servidor', 500, $detail);
        } else {
            Response::error('Error interno del servidor. Contacta al administrador con el id: ' . $errorId, 500, ['error_id' => $errorId]);
        }
    }
}
