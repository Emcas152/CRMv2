<?php
/**
 * Diagnóstico y reparación de conexión a base de datos local
 */

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargar variables de entorno
require_once __DIR__ . '/../core/helpers.php';

$result = [
    'step' => 'Diagnóstico',
    'env_file_used' => null,
    'env_vars' => [],
    'connection_attempts' => [],
    'success' => false
];

// Verificar qué archivo .env se está usando
$envLocal = __DIR__ . '/../.env.local';
$envProd = __DIR__ . '/../.env';

if (file_exists($envLocal)) {
    $result['env_file_used'] = '.env.local (LOCAL - correcto)';
    $result['env_file_path'] = $envLocal;
} elseif (file_exists($envProd)) {
    $result['env_file_used'] = '.env (PRODUCCIÓN - incorrecto para local)';
    $result['env_file_path'] = $envProd;
}

// Obtener variables actuales
$result['env_vars'] = [
    'DB_HOST' => getenv('DB_HOST') ?: 'not set',
    'DB_PORT' => getenv('DB_PORT') ?: 'not set',
    'DB_NAME' => getenv('DB_NAME') ?: 'not set',
    'DB_USER' => getenv('DB_USER') ?: 'not set',
    'DB_PASS' => (getenv('DB_PASS') !== false) ? (empty(getenv('DB_PASS')) ? '(vacío)' : '(configurado)') : 'not set'
];

// Intentar diferentes configuraciones
$attempts = [
    [
        'name' => 'Configuración actual (.env)',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'dbname' => getenv('DB_NAME') ?: 'crm_spa_medico'
    ],
    [
        'name' => 'XAMPP típico (root sin password)',
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',
        'dbname' => 'crm_spa_medico'
    ],
    [
        'name' => 'XAMPP típico (127.0.0.1)',
        'host' => '127.0.0.1',
        'user' => 'root',
        'pass' => '',
        'dbname' => 'crm_spa_medico'
    ]
];

foreach ($attempts as $config) {
    $attemptResult = [
        'name' => $config['name'],
        'config' => [
            'host' => $config['host'],
            'user' => $config['user'],
            'pass_empty' => empty($config['pass'])
        ],
        'success' => false,
        'error' => null
    ];

    try {
        // Paso 1: Conectar y crear base de datos
        $dsn = "mysql:host={$config['host']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]);

        $attemptResult['connection'] = 'OK - MySQL conectado';

        // Crear base de datos si no existe
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $attemptResult['database_created'] = "OK - Base de datos '{$config['dbname']}' creada/verificada";
        
        // Cerrar conexión
        $pdo = null;

        // Paso 2: Reconectar con la base de datos seleccionada
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]);

        // Verificar si hay tablas
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        unset($stmt);
        $pdo = null; // Cerrar conexión
        
        if (count($tables) === 0) {
            // Paso 3: Reconectar para crear tablas
            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ]);
            
            // Ejecutar install.sql
            $sqlFile = __DIR__ . '/../install.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                
                // Ejecutar en bloques
                $statements = array_filter(
                    array_map('trim', explode(';', $sql)),
                    function($stmt) {
                        return !empty($stmt) && strpos(trim($stmt), '--') !== 0;
                    }
                );

                foreach ($statements as $statement) {
                    if (!empty(trim($statement))) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                            // Ignorar errores de "tabla ya existe"
                            if (strpos($e->getMessage(), 'already exists') === false) {
                                throw $e;
                            }
                        }
                    }
                }

                $attemptResult['tables_created'] = count($statements) . ' statements ejecutados';

                // Crear usuario admin
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password, role, phone, active) 
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                
                $stmt->execute([
                    'Super Administrador',
                    'superadmin@crm.com',
                    password_hash('superadmin123', PASSWORD_BCRYPT),
                    'superadmin',
                    '5551234567'
                ]);
                // Agregar otros usuarios (ignorar si ya existen)
                $additionalUsers = [
                    ['Administrador', 'admin@crm.com', 'admin123', 'admin',  '5550000001'],
                    ['Doctor',       'doctor@crm.com', 'doctor123', 'doctor', '5550000002'],
                    ['Personal',     'staff@crm.com',  'staff123',  'staff',  '5550000003'],
                    ['Paciente',     'patient@crm.com','patient123','patient','5550000004'],
                ];

                foreach ($additionalUsers as $u) {
                    try {
                        $stmt->execute([
                            $u[0],
                            $u[1],
                            password_hash($u[2], PASSWORD_BCRYPT),
                            $u[3],
                            $u[4]
                        ]);
                    } catch (PDOException $e) {
                        // Ignorar si el usuario ya existe (entrada duplicada), re-lanzar otros errores
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            // no-op
                        } else {
                            throw $e;
                        }
                    }
                }

                $stmt->closeCursor();
                unset($stmt);
                $stmt->closeCursor();
                unset($stmt);

                $attemptResult['admin_created'] = 'Usuario superadmin@crm.com / superadmin123 creado';
            }
            
            $pdo = null; // Cerrar conexión
        } else {
            $attemptResult['tables_found'] = count($tables) . ' tablas: ' . implode(', ', $tables);
        }

        $attemptResult['success'] = true;
        $attemptResult['recommended_env'] = [
            'DB_HOST' => $config['host'],
            'DB_USER' => $config['user'],
            'DB_PASS' => $config['pass'],
            'DB_NAME' => $config['dbname']
        ];

        $result['success'] = true;
        $result['working_config'] = $attemptResult;
        break; // Si funciona, no seguir probando

    } catch (PDOException $e) {
        $attemptResult['error'] = $e->getMessage();
    }

    $result['connection_attempts'][] = $attemptResult;
}

if ($result['success']) {
    $result['next_steps'] = [
        '1. Actualiza tu .env.local con la configuración que funcionó',
        '2. Reinicia el servidor PHP: php -S localhost:8000 -t public',
        '3. Prueba el login en: http://localhost:4200',
        '4. Credenciales: superadmin@crm.com / superadmin123'
    ];

    // Actualizar .env.local automáticamente
    if (isset($result['working_config']['recommended_env'])) {
        $envContent = file_get_contents($envLocal);
        $recommended = $result['working_config']['recommended_env'];
        
        $envContent = preg_replace('/DB_HOST=.*/', "DB_HOST={$recommended['DB_HOST']}", $envContent);
        $envContent = preg_replace('/DB_USER=.*/', "DB_USER={$recommended['DB_USER']}", $envContent);
        $envContent = preg_replace('/DB_PASS=.*/', "DB_PASS={$recommended['DB_PASS']}", $envContent);
        $envContent = preg_replace('/DB_NAME=.*/', "DB_NAME={$recommended['DB_NAME']}", $envContent);
        
        file_put_contents($envLocal, $envContent);
        
        $result['env_updated'] = 'Archivo .env.local actualizado automáticamente';
    }

    http_response_code(200);
} else {
    $result['help'] = [
        'Verifica que MySQL/MariaDB esté corriendo',
        'En XAMPP: Abre el Panel de Control y asegúrate de que Apache y MySQL estén en verde',
        'Verifica el puerto de MySQL (por defecto 3306)',
        'Si usas contraseña en root, actualiza DB_PASS en .env.local'
    ];
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
