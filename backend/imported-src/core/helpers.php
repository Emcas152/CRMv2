<?php
/**
 * Helper para cargar variables de entorno desde archivo .env
 */

function loadEnv($filePath = null) {
    // Si no se especifica, buscar .env.local primero, luego .env
    if ($filePath === null) {
        $dir = __DIR__ . '/../';
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
        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Separar clave=valor
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            
            $key = trim($key);
            $value = trim($value);
            
            // Remover comillas
            $value = trim($value, '"\'');
            
            // Solo establecer si NO está ya definido (para que .env.local tenga prioridad)
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
