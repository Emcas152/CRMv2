<?php
/**
 * Punto de entrada raíz - Redirige a public/
 */

// Verificar si existe public/index.php
if (file_exists(__DIR__ . '/public/index.php')) {
    header('Location: public/');
    exit;
}

// Si no existe, mostrar error
http_response_code(500);
echo json_encode([
    'error' => 'Backend no configurado correctamente',
    'message' => 'Falta el directorio public/',
    'hint' => 'Asegúrate de subir toda la carpeta backend-php-puro'
]);
