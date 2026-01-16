<?php
namespace App\Core;

class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);

        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if (!$error) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
            if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
                return;
            }

            self::handle(new \ErrorException(
                $error['message'] ?? 'Fatal error',
                0,
                $error['type'] ?? 0,
                $error['file'] ?? '',
                $error['line'] ?? 0
            ));
        });
    }

    public static function handle(\Throwable $e)
    {
        $errorId = uniqid('err_', true);

        $message = sprintf("[%s] %s in %s on line %s\nStack trace:\n%s", $errorId, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

        error_log($message);

        // Always try to persist errors to a predictable file for shared hosting debugging.
        // Priority: env APP_LOG_FILE -> constant APP_LOG_FILE -> backend/tools/app_error.log
        $logFile = getenv('APP_LOG_FILE');
        if (!$logFile && defined('APP_LOG_FILE') && APP_LOG_FILE) {
            $logFile = (string)APP_LOG_FILE;
        }
        if (!$logFile) {
            $logFile = __DIR__ . '/../../tools/app_error.log';
        }

        // Write a one-line marker plus the full message (which may contain newlines).
        $marker = date('c') . " [{$errorId}] " . get_class($e) . ': ' . $e->getMessage() . "\n";
        @file_put_contents($logFile, $marker . $message . "\n", FILE_APPEND | LOCK_EX);

        $config = @include __DIR__ . '/../../config/app.php';
        $env = $config['app_env'] ?? getenv('APP_ENV') ?: 'production';

        if ($env !== 'production') {
            $detail = ['error_id' => $errorId, 'exception' => $e->getMessage(), 'trace' => $e->getTrace()];
            Response::error('Error interno del servidor', 500, $detail);
        } else {
            Response::error('Error interno del servidor. Contacta al administrador con el id: ' . $errorId, 500, ['error_id' => $errorId]);
        }
    }
}
