<?php
/**
 * Helper para cargar variables de entorno desde archivo .env
 * Mantener en espacio global para compatibilidad con código que usa funciones globales.
 */

function loadEnv($filePath = null) {
    if ($filePath === null) {
        $dir = __DIR__ . '/../../';
        if (file_exists($dir . '.env.local')) {
            $filePath = $dir . '.env.local';
        } elseif (file_exists($dir . '.env')) {
            $filePath = $dir . '.env';
        } else {
            return false;
        }
    }

    if (!file_exists($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }

    return true;
}

// Cargar automáticamente al incluir este archivo
loadEnv();
