<?php
/**
 * Production DB Installer (MySQL) - PHP
 *
 * Creates the target database (if missing) and applies the official SQL schema + phase schemas.
 * Designed to be idempotent and compatible with MySQL versions that do NOT support:
 *  - ALTER TABLE ... ADD COLUMN IF NOT EXISTS
 *  - CREATE INDEX IF NOT EXISTS
 *
 * Usage examples:
 *   php backend/tools/install-production-db.php --help
 *   php backend/tools/install-production-db.php --apply
 *   php backend/tools/install-production-db.php --apply --db=crm_spa_medico
 *   php backend/tools/install-production-db.php --apply --with-seed
 *   php backend/tools/install-production-db.php --apply --no-wipe
 *
 * Credentials resolution order:
 *  1) CLI flags --host/--port/--user/--pass/--db
 *  2) Environment variables (DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME)
 *  3) backend/config/database.php defaults
 */

declare(strict_types=1);

// Version stamp to confirm the server is running the expected installer build.
// Bump this when deploying changes to shared hosting.
const INSTALLER_VERSION = '2026-01-05.mysql8-ifnotexists-rewrite-v10-no-prepared-profiles';

// Load .env automatically (same behavior as backend bootstrap)
// so this installer can run using production credentials without SSH.
@require_once __DIR__ . '/../app/Core/helpers.php';

// PHP 7.x compatibility (shared hosting). PHP 8+ provides str_ends_with.
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') {
            return true;
        }
        $len = strlen($needle);
        if ($len === 0) {
            return true;
        }
        return substr($haystack, -$len) === $needle;
    }
}

function stderr(string $message): void {
    if (defined('STDERR')) {
        @fwrite(STDERR, $message . PHP_EOL);
        return;
    }

    // Web SAPI fallback
    echo $message . "\n";
}

function stdout(string $message): void {
    if (defined('STDOUT')) {
        @fwrite(STDOUT, $message . PHP_EOL);
        return;
    }

    // Web SAPI fallback
    echo $message . "\n";
}

function parseArgs(array $argv): array {
    $out = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--apply') { $out['apply'] = true; continue; }
        if ($arg === '--with-seed') { $out['with_seed'] = true; continue; }
        if ($arg === '--skip-create-db') { $out['skip_create_db'] = true; continue; }
        if ($arg === '--wipe') { $out['wipe'] = true; continue; }
        if ($arg === '--no-wipe') { $out['no_wipe'] = true; continue; }
        if ($arg === '--print-profiles') { $out['print_profiles'] = true; continue; }
        if ($arg === '--no-print-profiles') { $out['no_print_profiles'] = true; continue; }
        if ($arg === '--no-phase2') { $out['no_phase2'] = true; continue; }
        if ($arg === '--no-phase3') { $out['no_phase3'] = true; continue; }

        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        }
    }
    return $out;
}

function loadDefaultDbConfig(): array {
    $configPath = __DIR__ . '/../config/database.php';
    if (is_file($configPath)) {
        /** @var array $cfg */
        $cfg = require $configPath;
        return $cfg;
    }

    return [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 3306,
        'database' => getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'crm'),
        'username' => getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root'),
        'password' => getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: ''),
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ];
}

function pdoConnectServer(array $cfg): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset']);
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function pdoConnectDatabase(array $cfg): PDO {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function createDatabaseIfMissing(PDO $pdo, string $dbName): void {
    $safe = str_replace('`', '``', $dbName);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function dropDatabaseIfExists(PDO $pdo, string $dbName): void {
    $safe = str_replace('`', '``', $dbName);
    $pdo->exec("DROP DATABASE IF EXISTS `{$safe}`");
}

function wipeDatabaseObjects(PDO $pdo, string $dbName): void {
    // Drops tables/views/triggers/routines/events inside the current database.
    // This is a fallback for shared hosting environments where DROP DATABASE is not permitted.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Events
    $stmt = $pdo->prepare('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)($row['EVENT_NAME'] ?? '');
        if ($name === '') continue;
        $safe = str_replace('`', '``', $name);
        $pdo->exec("DROP EVENT IF EXISTS `{$safe}`");
    }

    // Triggers
    $stmt = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)($row['TRIGGER_NAME'] ?? '');
        if ($name === '') continue;
        $safe = str_replace('`', '``', $name);
        $pdo->exec("DROP TRIGGER IF EXISTS `{$safe}`");
    }

    // Routines (procedures/functions)
    $stmt = $pdo->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?');
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)($row['ROUTINE_NAME'] ?? '');
        $type = strtoupper((string)($row['ROUTINE_TYPE'] ?? ''));
        if ($name === '' || ($type !== 'PROCEDURE' && $type !== 'FUNCTION')) continue;
        $safe = str_replace('`', '``', $name);
        $pdo->exec("DROP {$type} IF EXISTS `{$safe}`");
    }

    // Views
    $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'");
    $stmt->execute([$dbName]);
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)($row['TABLE_NAME'] ?? '');
        if ($name === '') continue;
        $safe = str_replace('`', '``', $name);
        $pdo->exec("DROP VIEW IF EXISTS `{$safe}`");
    }

    // Base tables
    $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'");
    $stmt->execute([$dbName]);
    $tables = $stmt->fetchAll();
    if (!empty($tables)) {
        $parts = [];
        foreach ($tables as $row) {
            $name = (string)($row['TABLE_NAME'] ?? '');
            if ($name === '') continue;
            $safe = str_replace('`', '``', $name);
            $parts[] = "`{$safe}`";
        }
        if (!empty($parts)) {
            $pdo->exec('DROP TABLE IF EXISTS ' . implode(', ', $parts));
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function splitSqlStatements(string $sql): array {
    // Strip UTF-8 BOM
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;

    // Delimiter-aware, line-based splitter.
    // This is intentionally simple and robust for our schema files.
    $statements = [];
    $delimiter = ';';
    $buffer = '';

    $lines = preg_split("/\r\n|\n|\r/", $sql);
    if ($lines === false) {
        $lines = [$sql];
    }

    foreach ($lines as $line) {
        // Handle DELIMITER directives
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "\n";

        // Emit statements when buffer ends with delimiter (trim-right aware)
        $trimRight = rtrim($buffer);
        if ($delimiter !== '' && str_ends_with($trimRight, $delimiter)) {
            $stmt = substr($trimRight, 0, -strlen($delimiter));
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$dbName, $table, $column]);
    $row = $stmt->fetch();
    return intval($row['c'] ?? 0) > 0;
}

function indexExists(PDO $pdo, string $dbName, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$dbName, $table, $index]);
    $row = $stmt->fetch();
    return intval($row['c'] ?? 0) > 0;
}

function routineExists(PDO $pdo, string $dbName, string $name, string $type): bool {
    $type = strtoupper($type);
    if ($type !== 'PROCEDURE' && $type !== 'FUNCTION') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = ?');
    $stmt->execute([$dbName, $name, $type]);
    $row = $stmt->fetch();
    return intval($row['c'] ?? 0) > 0;
}

function splitTopLevelCommas(string $s): array {
    $parts = [];
    $buf = '';
    $len = strlen($s);
    $depth = 0;
    $inSingle = false;
    $inDouble = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];

        if ($ch === "'" && !$inDouble) {
            $prev = $i > 0 ? $s[$i - 1] : '';
            if ($prev !== '\\') {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle) {
            $prev = $i > 0 ? $s[$i - 1] : '';
            if ($prev !== '\\') {
                $inDouble = !$inDouble;
            }
        }

        if (!$inSingle && !$inDouble) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && $depth > 0) {
                $depth--;
            }

            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
        }

        $buf .= $ch;
    }

    $tail = trim($buf);
    if ($tail !== '') {
        $parts[] = $tail;
    }
    return $parts;
}

function stripLeadingSqlComments(string $sql): string {
    $s = $sql;
    for ($i = 0; $i < 50; $i++) {
        $before = $s;

        // Remove leading line comments (MySQL supports -- and #)
        $s = preg_replace('/^\s*(?:(?:--[^\n]*\n)|(?:#[^\n]*\n))+/', '', $s) ?? $s;

        // Remove leading block comments /* ... */
        $s = preg_replace('/^\s*\/\*.*?\*\//s', '', $s) ?? $s;

        if ($s === $before) {
            break;
        }
    }

    return ltrim($s);
}

function runStatement(PDO $pdo, string $dbName, string $statement): void {
    // Some schema files place `--` comments immediately before a statement. Strip them so
    // our pattern matching (e.g., ALTER TABLE rewrites) can trigger reliably.
    $statement = stripLeadingSqlComments($statement);

    $trimmed = trim($statement);
    if ($trimmed === '' || strlen($trimmed) === 0) {
        return;
    }

    $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;

    // Additional safety: skip if normalized statement is empty or only whitespace
    if (trim($normalized) === '' || strlen(trim($normalized)) === 0) {
        return;
    }

    // DROP INDEX IF EXISTS idx_name ON table
    if (preg_match('/^DROP INDEX\s+IF EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+ON\s+`?([a-zA-Z0-9_]+)`?$/i', $normalized, $m)) {
        $index = $m[1];
        $table = $m[2];
        if (!indexExists($pdo, $dbName, $table, $index)) {
            return;
        }
        $safeIndex = str_replace('`', '``', $index);
        $safeTable = str_replace('`', '``', $table);
        $pdo->exec("DROP INDEX `{$safeIndex}` ON `{$safeTable}`");
        return;
    }

    // CREATE PROCEDURE/FUNCTION IF NOT EXISTS name(...) ...
    if (preg_match('/^CREATE\s+(PROCEDURE|FUNCTION)\s+IF NOT EXISTS\s+`?([a-zA-Z0-9_]+)`?\s*(.*)$/is', $trimmed, $m)) {
        $type = strtoupper($m[1]);
        $name = $m[2];
        $rest = $m[3];
        if (routineExists($pdo, $dbName, $name, $type)) {
            stdout("  Skipping existing {$type}: {$name}");
            return;
        }
        $safeName = str_replace('`', '``', $name);
        // Remove IF NOT EXISTS and execute
        $cleanStatement = preg_replace(
            '/^CREATE\s+(PROCEDURE|FUNCTION)\s+IF NOT EXISTS\s+/i',
            "CREATE $1 ",
            $trimmed
        );
        $pdo->exec($cleanStatement);
        return;
    }

    // Proactively handle IF NOT EXISTS patterns to be compatible with MySQL (no ADD COLUMN IF NOT EXISTS).
    // Handles both single and multi-column ALTER TABLE by rewriting and applying column-by-column.
    if (stripos($normalized, 'ALTER TABLE') === 0 && stripos($normalized, 'ADD COLUMN IF NOT EXISTS') !== false) {
        if (preg_match('/^ALTER TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/i', $normalized, $m)) {
            $table = $m[1];
            $ops = $m[2];

            // Rewrite to MySQL-compatible syntax, then split and apply idempotently.
            $ops = preg_replace('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $ops) ?? $ops;
            $segments = splitTopLevelCommas($ops);
            $safeTable = str_replace('`', '``', $table);

            foreach ($segments as $seg) {
                $segTrim = trim($seg);
                if ($segTrim === '') continue;
                $segNorm = preg_replace('/\s+/', ' ', $segTrim) ?? $segTrim;

                if (preg_match('/^ADD COLUMN\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/i', $segNorm, $mm)) {
                    $column = $mm[1];
                    $rest = trim($mm[2]);
                    if ($rest === '') {
                        continue; // Skip empty column definition
                    }
                    if (columnExists($pdo, $dbName, $table, $column)) {
                        continue;
                    }
                    $safeCol = str_replace('`', '``', $column);
                    $pdo->exec("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeCol}` {$rest}");
                    continue;
                }

                // For any other operations in the ALTER TABLE statement, run them as-is.
                if ($segTrim !== '' && strlen($segTrim) > 0) {
                    $pdo->exec("ALTER TABLE `{$safeTable}` {$segTrim}");
                }
            }
            return;
        }
    }

    // 2) CREATE [UNIQUE] INDEX IF NOT EXISTS
    if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF NOT EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+ON\s+`?([a-zA-Z0-9_]+)`?\s*\((.*)\)$/i', $normalized, $m)) {
        $unique = trim((string)($m[1] ?? '')) !== '';
        $index = $m[2];
        $table = $m[3];
        $cols = $m[4];

        if (indexExists($pdo, $dbName, $table, $index)) {
            return;
        }

        $safeIndex = str_replace('`', '``', $index);
        $safeTable = str_replace('`', '``', $table);
        $prefix = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        $pdo->exec("{$prefix} `{$safeIndex}` ON `{$safeTable}` ({$cols})");
        return;
    }

    // 3) CREATE [UNIQUE] INDEX (without IF NOT EXISTS) -> make idempotent
    if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+`?([a-zA-Z0-9_]+)`?\s+ON\s+`?([a-zA-Z0-9_]+)`?\s*\((.*)\)$/i', $normalized, $m)) {
        $unique = trim((string)($m[1] ?? '')) !== '';
        $index = $m[2];
        $table = $m[3];
        $cols = $m[4];

        if (indexExists($pdo, $dbName, $table, $index)) {
            return;
        }

        $safeIndex = str_replace('`', '``', $index);
        $safeTable = str_replace('`', '``', $table);
        $prefix = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        $pdo->exec("{$prefix} `{$safeIndex}` ON `{$safeTable}` ({$cols})");
        return;
    }

    // Default: run as-is, but provide a safety fallback for IF NOT EXISTS syntax errors.
    try {
        // Additional validation before executing
        if (trim($normalized) === '' || strlen(trim($normalized)) === 0) {
            stderr("WARN: Attempted to execute empty statement, skipping.");
            return;
        }
        $pdo->exec($statement);
    } catch (PDOException $e) {
        // Log the problematic statement for debugging
        stderr("ERROR executing SQL: " . substr($normalized, 0, 200));
        stderr("Full error: " . $e->getMessage());
        $msg = $e->getMessage();
        if (stripos($msg, 'IF NOT EXISTS') !== false && stripos($normalized, 'ADD COLUMN IF NOT EXISTS') !== false) {
            // Retry via the rewrite path above.
            if (preg_match('/^ALTER TABLE\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/i', $normalized, $m)) {
                $table = $m[1];
                $ops = $m[2];
                $ops = preg_replace('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $ops) ?? $ops;
                $segments = splitTopLevelCommas($ops);
                $safeTable = str_replace('`', '``', $table);

                foreach ($segments as $seg) {
                    $segTrim = trim($seg);
                    if ($segTrim === '') continue;
                    $segNorm = preg_replace('/\s+/', ' ', $segTrim) ?? $segTrim;

                    if (preg_match('/^ADD COLUMN\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/i', $segNorm, $mm)) {
                        $column = $mm[1];
                        $rest = trim($mm[2]);
                        if ($rest === '') {
                            continue; // Skip empty column definition
                        }
                        if (columnExists($pdo, $dbName, $table, $column)) {
                            continue;
                        }
                        $safeCol = str_replace('`', '``', $column);
                        $pdo->exec("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeCol}` {$rest}");
                        continue;
                    }

                    if ($segTrim !== '' && strlen($segTrim) > 0) {
                        $pdo->exec("ALTER TABLE `{$safeTable}` {$segTrim}");
                    }
                }
                return;
            }
        }

        throw $e;
    }
}

function runSqlFile(PDO $pdo, string $dbName, string $filePath): void {
    if (!is_file($filePath)) {
        throw new RuntimeException("SQL file not found: {$filePath}");
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Failed to read SQL file: {$filePath}");
    }

    $statements = splitSqlStatements($sql);
    $stmtNum = 0;
    foreach ($statements as $stmt) {
        $stmtNum++;
        $trim = trim($stmt);
        if ($trim === '') {
            stdout("  Skipping empty statement #{$stmtNum}");
            continue;
        }

        $preview = substr(preg_replace('/\s+/', ' ', $trim), 0, 80);
        stdout("  [{$stmtNum}] {$preview}...");

        try {
            runStatement($pdo, $dbName, $trim);
        } catch (Throwable $e) {
            stderr("FAILED at statement #{$stmtNum} in " . basename($filePath));
            stderr("Statement preview: {$preview}");
            stderr("Full statement: " . substr($trim, 0, 500));
            throw $e;
        }
    }
}

function fetchUserIdByEmail(PDO $pdo, string $email): ?int {
    $emailQuoted = sqlQuote($pdo, $email);
    $row = $pdo->query("SELECT id FROM users WHERE email = {$emailQuoted} LIMIT 1")?->fetch();
    if (!$row) {
        return null;
    }
    return intval($row['id'] ?? 0) ?: null;
}

function sqlQuote(PDO $pdo, mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$value);
}

function isPreparedStatementReprepareError(Throwable $e): bool {
    if (!($e instanceof PDOException)) {
        return false;
    }
    $info = $e->errorInfo;
    $driverCode = is_array($info) ? intval($info[1] ?? 0) : 0;
    if ($driverCode === 1615) {
        return true;
    }
    $msg = $e->getMessage();
    return stripos($msg, 'needs to be re-prepared') !== false;
}

function execWithRetry(PDO $pdo, string $sql, int $attempts = 2): void {
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $pdo->exec($sql);
            return;
        } catch (Throwable $e) {
            if ($attempt < $attempts && isPreparedStatementReprepareError($e)) {
                // Shared hosting MySQL can intermittently throw 1615 after DDL.
                usleep(150000);
                continue;
            }
            throw $e;
        }
    }
}

function upsertUser(PDO $pdo, array $row): int {
    // Avoid prepared statements here (Hostalia/MySQL can throw 1615 intermittently after DDL).
    $name = sqlQuote($pdo, $row['name'] ?? '');
    $email = sqlQuote($pdo, $row['email'] ?? '');
    $password = sqlQuote($pdo, $row['password'] ?? '');
    $role = sqlQuote($pdo, $row['role'] ?? '');
    $phone = sqlQuote($pdo, $row['phone'] ?? '');

    $sql =
        'INSERT INTO users (name, email, password, role, phone, active, email_verified) '
        . "VALUES ({$name}, {$email}, {$password}, {$role}, {$phone}, 1, 1) "
        . 'ON DUPLICATE KEY UPDATE '
        . 'name = VALUES(name), '
        . 'password = VALUES(password), '
        . 'role = VALUES(role), '
        . 'phone = VALUES(phone), '
        . 'active = 1, '
        . 'email_verified = 1, '
        . 'updated_at = CURRENT_TIMESTAMP';

    execWithRetry($pdo, $sql, 3);

    $id = intval($pdo->lastInsertId());
    if ($id > 0) {
        return $id;
    }

    $existing = fetchUserIdByEmail($pdo, (string)$row['email']);
    if ($existing === null) {
        throw new RuntimeException('Failed to upsert user and re-fetch id for: ' . $row['email']);
    }
    return $existing;
}

function createRoleProfiles(PDO $pdo): array {
    // Creates one default user per role (minimal production-friendly baseline).
    // Passwords are set to known values to allow first-login; change them after install.
    $users = [
        [
            'name' => 'Super Administrador',
            'email' => 'superadmin@crm.com',
            'password_plain' => 'superadmin123',
            'role' => 'superadmin',
            'phone' => '+502 0000-0000',
        ],
        [
            'name' => 'Administrador del Sistema',
            'email' => 'admin@crm.com',
            'password_plain' => 'admin123',
            'role' => 'admin',
            'phone' => '+502 1234-5678',
        ],
        [
            'name' => 'Dr. Carlos Méndez',
            'email' => 'doctor@crm.com',
            'password_plain' => 'doctor123',
            'role' => 'doctor',
            'phone' => '+502 2345-6789',
        ],
        [
            'name' => 'Ana López',
            'email' => 'staff@crm.com',
            'password_plain' => 'staff123',
            'role' => 'staff',
            'phone' => '+502 3456-7890',
        ],
        [
            'name' => 'María González',
            'email' => 'patient@crm.com',
            'password_plain' => 'patient123',
            'role' => 'patient',
            'phone' => '+502 5551-1234',
        ],
    ];

    $idsByRole = [];
    foreach ($users as $u) {
        $idsByRole[$u['role']] = upsertUser($pdo, [
            'name' => $u['name'],
            'email' => $u['email'],
            'password' => password_hash($u['password_plain'], PASSWORD_BCRYPT),
            'role' => $u['role'],
            'phone' => $u['phone'],
        ]);
    }

    // Create linked staff member records for doctor/staff, if tables exist.
    $tableCheck = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members'");
    $hasStaffMembers = intval(($tableCheck?->fetch()['c'] ?? 0)) > 0;
    if ($hasStaffMembers) {
        $doctorId = intval($idsByRole['doctor'] ?? 0);
        $staffId = intval($idsByRole['staff'] ?? 0);

        execWithRetry($pdo, "DELETE FROM staff_members WHERE user_id = {$doctorId}", 3);
        execWithRetry(
            $pdo,
            'INSERT INTO staff_members (user_id, name, position, phone) VALUES ('
            . $doctorId . ', '
            . sqlQuote($pdo, 'Dr. Carlos Méndez') . ', '
            . sqlQuote($pdo, 'Doctor') . ', '
            . sqlQuote($pdo, '+502 2345-6789')
            . ')',
            3
        );

        execWithRetry($pdo, "DELETE FROM staff_members WHERE user_id = {$staffId}", 3);
        execWithRetry(
            $pdo,
            'INSERT INTO staff_members (user_id, name, position, phone) VALUES ('
            . $staffId . ', '
            . sqlQuote($pdo, 'Ana López') . ', '
            . sqlQuote($pdo, 'Staff') . ', '
            . sqlQuote($pdo, '+502 3456-7890')
            . ')',
            3
        );
    }

    // Create linked patient profile for patient role, if patients table exists.
    $tableCheck = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'");
    $hasPatients = intval(($tableCheck?->fetch()['c'] ?? 0)) > 0;
    if ($hasPatients) {
        $patientId = intval($idsByRole['patient'] ?? 0);
        execWithRetry($pdo, "DELETE FROM patients WHERE user_id = {$patientId}", 3);
        execWithRetry(
            $pdo,
            'INSERT INTO patients (user_id, name, email, phone, birthday, address, nit, loyalty_points) VALUES ('
            . $patientId . ', '
            . sqlQuote($pdo, 'María González') . ', '
            . sqlQuote($pdo, 'patient@crm.com') . ', '
            . sqlQuote($pdo, '+502 5551-1234') . ', '
            . sqlQuote($pdo, '1985-03-15') . ', '
            . sqlQuote($pdo, 'Guatemala') . ', '
            . 'NULL' . ', '
            . '0'
            . ')',
            3
        );
    }

    return $users;
}

function printRoleProfiles(array $profiles): void {
    stdout('');
    stdout('== Default Profiles ==');
    foreach ($profiles as $p) {
        $role = (string)($p['role'] ?? '');
        $email = (string)($p['email'] ?? '');
        $pass = (string)($p['password_plain'] ?? '');
        stdout("- {$role}: {$email} / {$pass}");
    }
    stdout('IMPORTANT: change these passwords after first login.');
    stdout('');
}

function verifyBasics(PDO $pdo, string $dbName): array {
    $requiredTables = ['users', 'patients', 'products', 'encryption_migrations'];
    $missingTables = [];

    foreach ($requiredTables as $t) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$dbName, $t]);
        $row = $stmt->fetch();
        if (intval($row['c'] ?? 0) === 0) {
            $missingTables[] = $t;
        }
    }

    $requiredColumns = [
        ['products', 'price_encrypted'],
        ['patients', 'email_encrypted'],
        ['patients', 'email_hash'],
        ['patients', 'phone_encrypted'],
        ['patients', 'phone_hash'],
        ['users', 'phone_encrypted'],
        ['users', 'phone_hash'],
    ];

    $missingColumns = [];
    foreach ($requiredColumns as [$table, $col]) {
        if (!columnExists($pdo, $dbName, $table, $col)) {
            $missingColumns[] = "{$table}.{$col}";
        }
    }

    return [
        'missing_tables' => $missingTables,
        'missing_columns' => $missingColumns,
    ];
}

function main(array $argv): int {
    $args = parseArgs($argv);

    if (!empty($args['help'])) {
        stdout('Production DB Installer (MySQL)');
        stdout('');
        stdout('Usage:');
        stdout('  php backend/tools/install-production-db.php --apply [--db=NAME] [--with-seed]');
        stdout('');
        stdout('Options:');
        stdout('  --apply             Execute changes (required).');
        stdout('  --wipe              Drop + recreate the database before install (default).');
        stdout('  --no-wipe           Do NOT wipe database before install.');
        stdout('  --print-profiles    Print default role profiles at the end (default).');
        stdout('  --no-print-profiles Do NOT print the default role profiles.');
        stdout('  --with-seed         Also load backend/docs/seed.mysql.sql (optional).');
        stdout('  --skip-create-db    Do not create database (assume it exists).');
        stdout('  --no-phase2         Skip phase2 schema files.');
        stdout('  --no-phase3         Skip phase3 schema files.');
        stdout('  --host=HOST         DB host override.');
        stdout('  --port=PORT         DB port override.');
        stdout('  --user=USER         DB username override.');
        stdout('  --pass=PASS         DB password override.');
        stdout('  --db=NAME           Database name override.');
        stdout('');
        return 0;
    }

    if (empty($args['apply'])) {
        stderr('Refusing to run without --apply (safety).');
        stderr('Run: php backend/tools/install-production-db.php --apply');
        return 2;
    }

    // Default: print profiles (user requested).
    $printProfiles = true;
    if (!empty($args['no_print_profiles'])) {
        $printProfiles = false;
    }
    if (!empty($args['print_profiles'])) {
        $printProfiles = true;
    }

    // By default, we wipe the database (per production installer requirement).
    $wipe = true;
    if (!empty($args['no_wipe'])) {
        $wipe = false;
    }
    if (!empty($args['wipe'])) {
        $wipe = true;
    }
    if (!empty($args['skip_create_db']) && $wipe) {
        stderr('Cannot use --skip-create-db together with wiping.');
        stderr('Remove --skip-create-db, or add --no-wipe.');
        return 2;
    }

    $cfg = loadDefaultDbConfig();

    // Apply overrides
    if (isset($args['host'])) $cfg['host'] = $args['host'];
    if (isset($args['port'])) $cfg['port'] = $args['port'];
    if (isset($args['user'])) $cfg['username'] = $args['user'];
    if (isset($args['pass'])) $cfg['password'] = $args['pass'];
    if (isset($args['db'])) $cfg['database'] = $args['db'];

    $dbName = (string)$cfg['database'];

    $docsDir = realpath(__DIR__ . '/../docs');
    if ($docsDir === false) {
        throw new RuntimeException('Cannot locate backend/docs directory');
    }

    $sqlFiles = [
        $docsDir . DIRECTORY_SEPARATOR . 'schema.mysql.sql',
    ];

    if (empty($args['no_phase2'])) {
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'phase2-rate-limiting-schema.sql';
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'phase2-login-blocking-schema.sql';
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'phase2-2fa-schema.sql';
    }

    if (empty($args['no_phase3'])) {
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'phase3-audit-ip-schema.sql';
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'phase3-encryption-schema.sql';
    }

    if (!empty($args['with_seed'])) {
        $sqlFiles[] = $docsDir . DIRECTORY_SEPARATOR . 'seed.mysql.sql';
    }

    stdout('== Production DB Installer ==');
    stdout('Host: ' . $cfg['host'] . ':' . $cfg['port']);
    stdout('DB:   ' . $dbName);
    stdout('User: ' . $cfg['username']);
    stdout('');

    // 1) Connect to server and (re)create DB
    $serverPdo = pdoConnectServer($cfg);
    if ($wipe) {
        stdout('Wiping database (drop + recreate)...');
        try {
            dropDatabaseIfExists($serverPdo, $dbName);
            createDatabaseIfMissing($serverPdo, $dbName);
        } catch (Throwable $e) {
            stderr('WARN: DROP DATABASE not permitted or failed; wiping objects inside schema instead.');
            // Ensure DB exists, then connect and wipe objects.
            createDatabaseIfMissing($serverPdo, $dbName);

            $tmpCfg = $cfg;
            $tmpCfg['database'] = $dbName;
            $tmpPdo = pdoConnectDatabase($tmpCfg);
            $tmpPdo->exec("SET NAMES {$cfg['charset']}");
            wipeDatabaseObjects($tmpPdo, $dbName);
        }
    } else {
        if (empty($args['skip_create_db'])) {
            stdout('Creating database if missing...');
            createDatabaseIfMissing($serverPdo, $dbName);
        } else {
            stdout('Skipping database creation (--skip-create-db).');
        }
    }

    // 2) Connect to DB and run SQL
    $dbPdo = pdoConnectDatabase($cfg);
    $dbPdo->exec("SET NAMES {$cfg['charset']}");

    foreach ($sqlFiles as $file) {
        stdout('Applying: ' . basename($file));
        runSqlFile($dbPdo, $dbName, $file);
    }

    // Force PDO to clear any cached prepared statements before inserting user data
    // This prevents "Prepared statement needs to be re-prepared" errors after ALTER TABLE
    $dbPdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // 2.5) Create one default profile per role
    stdout('Creating default role profiles (users)...');
    $profiles = createRoleProfiles($dbPdo);
    if ($printProfiles) {
        printRoleProfiles($profiles);
    }

    // 3) Verify
    stdout('Verifying installation...');
    $check = verifyBasics($dbPdo, $dbName);

    if (!empty($check['missing_tables']) || !empty($check['missing_columns'])) {
        stderr('Installation finished with missing elements:');
        if (!empty($check['missing_tables'])) {
            stderr('Missing tables: ' . implode(', ', $check['missing_tables']));
        }
        if (!empty($check['missing_columns'])) {
            stderr('Missing columns: ' . implode(', ', $check['missing_columns']));
        }
        return 1;
    }

    stdout('✅ Installation OK');
    return 0;
}

// Auto-run only when executed via CLI. When included from a web entrypoint,
// it behaves like a library and the caller can invoke main([...]) manually.
if (PHP_SAPI === 'cli') {
    try {
        exit(main($argv));
    } catch (Throwable $e) {
        stderr('❌ Installer failed: ' . $e->getMessage());
        exit(1);
    }
}
