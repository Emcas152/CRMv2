<?php
/**
 * Secure viewer for backend error logs by error_id (err_...).
 *
 * Usage:
 * - Set PEEK_ERROR_TOKEN in backend/.env (recommended) or reuse INSTALLER_TOKEN.
 * - Open:
 *   /backend/tools/peek-error.php?token=...&id=err_xxx
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

@ignore_user_abort(true);
@set_time_limit(0);

// Load .env
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    http_response_code(500);
    echo "ERROR: Cannot locate backend root\n";
    exit;
}
@require_once $root . '/app/Core/helpers.php';

$expectedToken = getenv('PEEK_ERROR_TOKEN') ?: (getenv('INSTALLER_TOKEN') ?: '');
$token = (string)($_GET['token'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
    http_response_code(404);
    echo "Not Found\n";
    exit;
}

$errorId = trim((string)($_GET['id'] ?? ''));
if ($errorId === '' || strpos($errorId, 'err_') !== 0) {
    http_response_code(400);
    echo "ERROR: missing/invalid id. Expected: id=err_...\n";
    exit;
}

$logFile = getenv('APP_LOG_FILE');
if (!$logFile) {
    // Must match backend/bootstrap.php default.
    $logFile = $root . '/tools/app_error.log';
}

echo "== Peek Error ==\n";
echo "id: {$errorId}\n";
echo "log: {$logFile}\n\n";

if (!is_file($logFile)) {
    http_response_code(404);
    echo "ERROR: log file not found\n";
    exit;
}

$size = @filesize($logFile);
if (!is_int($size) || $size <= 0) {
    http_response_code(404);
    echo "ERROR: log file empty\n";
    exit;
}

// Read last chunk to keep it fast on shared hosting.
$maxBytes = 512 * 1024; // 512KB
$start = max(0, $size - $maxBytes);
$fh = @fopen($logFile, 'rb');
if (!$fh) {
    http_response_code(500);
    echo "ERROR: cannot open log file\n";
    exit;
}
@fseek($fh, $start);
$data = @stream_get_contents($fh);
@fclose($fh);

if (!is_string($data) || $data === '') {
    http_response_code(500);
    echo "ERROR: failed to read log file\n";
    exit;
}

$lines = preg_split('/\r\n|\r|\n/', $data);
$out = [];
foreach ($lines as $line) {
    if (strpos($line, $errorId) !== false) {
        $out[] = $line;
    }
}

if (!$out) {
    echo "NOT FOUND in last {$maxBytes} bytes.\n";
    echo "Tip: trigger the error again, then refresh this page.\n";
    exit;
}

echo "== Matches ==\n";
foreach ($out as $line) {
    echo $line . "\n";
}
