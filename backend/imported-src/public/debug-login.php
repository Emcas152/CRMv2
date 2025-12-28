<?php
/**
 * Script de Depuración de Login
 * Verifica usuarios en la base de datos y prueba el login
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Depuración de Login - CRM Spa Médico</h1>";

try {
    $db = Database::getInstance()->getConnection();

    echo "<h2>1️⃣ Verificar Usuarios en la Base de Datos</h2>";

    // Listar todos los usuarios
    $stmt = $db->query("SELECT id, name, email, role, created_at FROM users ORDER BY id");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "<p style='color: red;'><strong>⚠️ NO HAY USUARIOS EN LA BASE DE DATOS</strong></p>";
        echo "<p>Necesitas ejecutar: <a href='seed-data.php'>seed-data.php</a> o <a href='create-superadmin.php'>create-superadmin.php</a></p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Creado</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td><strong>{$user['email']}</strong></td>";
            echo "<td><span style='background: #4CAF50; color: white; padding: 3px 8px; border-radius: 3px;'>{$user['role']}</span></td>";
            echo "<td>{$user['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    echo "<h2>2️⃣ Probar Login de Superadmin</h2>";

    $testEmail = 'superadmin@crm.com';
    $testPassword = 'superadmin123';

    // Buscar usuario
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$testEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "<p style='color: red;'><strong>❌ Usuario superadmin@crm.com NO ENCONTRADO</strong></p>";
        echo "<p>Ejecuta: <a href='create-superadmin.php'>create-superadmin.php</a> para crearlo</p>";
    } else {
        echo "<p style='color: green;'><strong>✅ Usuario encontrado:</strong></p>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> {$user['id']}</li>";
        echo "<li><strong>Nombre:</strong> {$user['name']}</li>";
        echo "<li><strong>Email:</strong> {$user['email']}</li>";
        echo "<li><strong>Rol:</strong> {$user['role']}</li>";
        echo "</ul>";

        // Verificar contraseña
        $passwordHash = $user['password'];
        $isPasswordValid = Auth::verifyPassword($testPassword, $passwordHash);

        echo "<h3>Verificación de Contraseña</h3>";
        if ($isPasswordValid) {
            echo "<p style='color: green; font-size: 20px;'><strong>✅ CONTRASEÑA CORRECTA</strong></p>";
            echo "<p>El login debería funcionar con:</p>";
            echo "<ul>";
            echo "<li><strong>Email:</strong> superadmin@crm.com</li>";
            echo "<li><strong>Password:</strong> superadmin123</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: red; font-size: 20px;'><strong>❌ CONTRASEÑA INCORRECTA</strong></p>";
            echo "<p>La contraseña almacenada NO coincide con 'superadmin123'</p>";
            echo "<p><strong>Solución:</strong> Ejecuta <a href='create-superadmin.php'>create-superadmin.php</a> para actualizar la contraseña</p>";
        }

        echo "<h3>Información Técnica</h3>";
        echo "<ul>";
        echo "<li><strong>Hash almacenado:</strong> <code>" . substr($passwordHash, 0, 60) . "...</code></li>";
        echo "<li><strong>Longitud del hash:</strong> " . strlen($passwordHash) . " caracteres</li>";
        echo "<li><strong>Algoritmo:</strong> " . (password_get_info($passwordHash)['algoName'] ?? 'unknown') . "</li>";
        echo "</ul>";
    }

    echo "<h2>3️⃣ Probar Generación de Token JWT</h2>";

    if (isset($user) && isset($isPasswordValid) && $user && $isPasswordValid) {
        try {
            $token = Auth::generateToken($user['id'], $user['email'], $user['role']);
            echo "<p style='color: green;'><strong>✅ Token JWT generado exitosamente</strong></p>";
            echo "<p><strong>Token:</strong> <code style='word-break: break-all;'>" . substr($token, 0, 100) . "...</code></p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'><strong>❌ Error al generar token:</strong> {$e->getMessage()}</p>";
        }
    }

    echo "<hr>";
    echo "<h2>🔧 Acciones Disponibles</h2>";
    echo "<ul>";
    echo "<li><a href='create-superadmin.php' style='color: blue;'><strong>Crear/Actualizar Superadmin</strong></a> - Crea o actualiza el usuario superadmin</li>";
    echo "<li><a href='seed-data.php' style='color: blue;'><strong>Seed Data</strong></a> - Crea todos los usuarios de prueba</li>";
    echo "<li><a href='debug-login.php' style='color: blue;'><strong>Refrescar esta página</strong></a> - Vuelve a verificar</li>";
    echo "</ul>";

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>❌ Error:</strong> {$e->getMessage()}</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p style='color: #666; font-size: 12px;'>Debug realizado: " . date('Y-m-d H:i:s') . "</p>";
