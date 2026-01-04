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
 *
 * Credentials resolution order:
 *  1) CLI flags --host/--port/--user/--pass/--db
 *  2) Environment variables (DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME)
 *  3) backend/config/database.php defaults
 */

declare(strict_types=1);

function stderr(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
}

function stdout(string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
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

function splitSqlStatements(string $sql): array {
    // Strip UTF-8 BOM
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;

    $statements = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $current .= $ch;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++; // consume '/'
            }
            continue;
        }

        // Start comments only when not inside quotes
        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-') {
                // MySQL '-- ' comment; we accept '--' at line start or after whitespace
                $prev = $i > 0 ? $sql[$i - 1] : "\n";
                if ($prev === "\n" || $prev === "\r" || ctype_space($prev)) {
                    $inLineComment = true;
                    $i++; // consume second '-'
                    continue;
                }
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++; // consume '*'
                continue;
            }
        }

        // Toggle quote states
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        // Statement boundary
        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    // Remove DELIMITER directives (not supported by this simple runner)
    $filtered = [];
    foreach ($statements as $stmt) {
        if (preg_match('/^DELIMITER\s+/i', ltrim($stmt))) {
            continue;
        }
        $filtered[] = $stmt;
    }

    return $filtered;
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

function runStatement(PDO $pdo, string $dbName, string $statement): void {
    $normalized = preg_replace('/\s+/', ' ', trim($statement)) ?? trim($statement);

    // Proactively handle IF NOT EXISTS patterns to be compatible with older MySQL.
    // 1) ALTER TABLE ... ADD COLUMN IF NOT EXISTS
    if (preg_match('/^ALTER TABLE\s+`?([a-zA-Z0-9_]+)`?\s+ADD COLUMN\s+IF NOT EXISTS\s+`?([a-zA-Z0-9_]+)`?\s+(.*)$/i', $normalized, $m)) {
        $table = $m[1];
        $column = $m[2];
        $rest = $m[3];

        if (columnExists($pdo, $dbName, $table, $column)) {
            return;
        }

        $safeTable = str_replace('`', '``', $table);
        $safeCol = str_replace('`', '``', $column);
        $pdo->exec("ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeCol}` {$rest}");
        return;
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

    // Default: run as-is
    $pdo->exec($statement);
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
    foreach ($statements as $stmt) {
        $trim = trim($stmt);
        if ($trim === '') continue;

        runStatement($pdo, $dbName, $trim);
    }
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

    // 1) Connect to server and create DB
    $serverPdo = pdoConnectServer($cfg);
    if (empty($args['skip_create_db'])) {
        stdout('Creating database if missing...');
        createDatabaseIfMissing($serverPdo, $dbName);
    } else {
        stdout('Skipping database creation (--skip-create-db).');
    }

    // 2) Connect to DB and run SQL
    $dbPdo = pdoConnectDatabase($cfg);
    $dbPdo->exec("SET NAMES {$cfg['charset']}");

    foreach ($sqlFiles as $file) {
        stdout('Applying: ' . basename($file));
        runSqlFile($dbPdo, $dbName, $file);
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

try {
    exit(main($argv));
} catch (Throwable $e) {
    stderr('❌ Installer failed: ' . $e->getMessage());
    exit(1);
}
