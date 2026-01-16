<?php
/**
 * One-time Web Runner for Production DB Installer
 *
 * HOW TO USE (recommended):
 * 1) Add this temporary variable to backend/.env on the server:
 *      INSTALLER_TOKEN=some-long-random-token
 * 2) Upload this file + backend/tools/install-production-db.php + backend/docs/*.sql
 * 3) Open in browser (GET) to see status:
 *      https://your-domain.com/backend/tools/run-installer-once.php?token=INSTALLER_TOKEN
 * 4) Execute with POST (destructive wipe by default):
 *      POST https://.../run-installer-once.php?token=...&confirm=YES_WIPE
 * 5) Delete this file after success.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

@ignore_user_abort(true);
@set_time_limit(0);

// Make errors visible in shared hosting environments.
error_reporting(E_ALL);
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');

$logFile = __DIR__ . '/installer_web.log';
@ini_set('error_log', $logFile);

register_shutdown_function(function () use ($logFile) {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $type = (int)($err['type'] ?? 0);
    $message = (string)($err['message'] ?? '');
    $file = (string)($err['file'] ?? '');
    $line = (int)($err['line'] ?? 0);

    echo "\nSHUTDOWN ERROR: type={$type}\n";
    echo "Message: {$message}\n";
    echo "File: {$file}:{$line}\n";
    echo "Log: {$logFile}\n";
});

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    http_response_code(500);
    echo "ERROR: Cannot locate backend root\n";
    exit;
}

// Load .env (same as backend)
@require_once $root . '/app/Core/helpers.php';

$expectedToken = getenv('INSTALLER_TOKEN') ?: '';
$token = (string)($_GET['token'] ?? '');

// Fail closed
if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
    // Return 404 to avoid advertising this endpoint
    http_response_code(404);
    echo "Not Found\n";
    exit;
}

$lockFile = __DIR__ . '/.installer_ran.lock';
if (is_file($lockFile)) {
    http_response_code(409);
    echo "LOCKED: installer already executed (lock file exists).\n";
    echo "Delete: backend/tools/.installer_ran.lock (only if you really intend to re-run).\n";
    exit;
}

$appEnv = getenv('APP_ENV') ?: '';
$dbHost = getenv('DB_HOST') ?: '';
$dbName = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: '');
$dbUser = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: '');

echo "== Web Installer Runner ==\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "APP_ENV: {$appEnv}\n";
echo "DB_HOST: {$dbHost}\n";
echo "DB_NAME: {$dbName}\n";
echo "DB_USER: {$dbUser}\n";
echo "PHP error log: {$logFile}\n";
echo "Runner file mtime: " . date('c', @filemtime(__FILE__) ?: time()) . "\n";
$installerPath = __DIR__ . '/install-production-db.php';
echo "Installer file mtime: " . date('c', @filemtime($installerPath) ?: time()) . "\n";
echo "\n";

echo "Safety notes:\n";
echo "- This WILL WIPE the database by default.\n";
echo "- After success, DELETE this file: backend/tools/run-installer-once.php\n";
echo "\n";

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo "Status: READY (send POST to execute)\n";
    echo "To execute:\n";
    echo "- Use POST with confirm=YES_WIPE\n";
    echo "Example: POST ?token=...&confirm=YES_WIPE\n";
    exit;
}

$confirm = (string)($_GET['confirm'] ?? '');
if ($confirm !== 'YES_WIPE') {
    http_response_code(400);
    echo "ERROR: missing confirm=YES_WIPE\n";
    exit;
}

// Create lock file to prevent double execution from retries.
if (@file_put_contents($lockFile, 'in-progress ' . date('c')) === false) {
    echo "ERROR: Cannot write lock file: {$lockFile}\n";
    exit;
}

echo "RUNNING...\n\n";
ob_implicit_flush(true);

$argv = [
    'install-production-db.php',
    '--apply',
];
if ($dbName !== '') {
    $argv[] = '--db=' . $dbName;
}

try {
    @require_once __DIR__ . '/install-production-db.php';
    if (!function_exists('main')) {
        throw new RuntimeException('installer main() not found.');
    }

    if (defined('INSTALLER_VERSION')) {
        echo "INSTALLER_VERSION: " . INSTALLER_VERSION . "\n\n";
    } else {
        echo "INSTALLER_VERSION: (not defined)\n\n";
    }

    $code = main($argv);
    echo "\nEXIT CODE: {$code}\n";
    if ($code === 0) {
        // Keep lock to prevent accidental reruns.
        @file_put_contents($lockFile, 'done ' . date('c'));
        echo "SUCCESS. Now DELETE backend/tools/run-installer-once.php\n";
    } else {
        echo "FAILED. Check output above.\n";
        // Allow retry without manual cleanup.
        @unlink($lockFile);
        echo "Lock cleared (retry allowed).\n";
    }
} catch (Throwable $e) {
    echo "\nFATAL: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Log: {$logFile}\n";
    // Allow retry without manual cleanup.
    @unlink($lockFile);
    echo "Lock cleared (retry allowed).\n";
}
