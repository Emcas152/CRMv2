<?php
require_once __DIR__ . '/app/Core/helpers.php';

// Ensure a predictable log file for ErrorHandler error_id traces.
// Can be overridden via APP_LOG_FILE in .env.
$defaultLogFile = __DIR__ . '/tools/app_error.log';
if (!defined('APP_LOG_FILE')) {
	define('APP_LOG_FILE', getenv('APP_LOG_FILE') ?: $defaultLogFile);
}

require_once __DIR__ . '/app/Core/Response.php';
require_once __DIR__ . '/app/Core/ErrorHandler.php';

\App\Core\ErrorHandler::register();
