<?php
/**
 * Clase de Sanitización de Datos (Protección XSS)
 */

class Sanitizer
{
    /**
     * Sanitiza un valor individual
     */
    public static function clean($value)
    {
        if (is_array($value)) {
            return self::cleanArray($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        // Convertir caracteres especiales a entidades HTML
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
        
        // Eliminar scripts
        $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);
        
        // Eliminar iframes
        $value = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $value);
        
        // Eliminar eventos JavaScript inline
        $value = preg_replace('/on\w+\s*=\s*["\'].*?["\']/i', '', $value);
        
        // Eliminar protocolos peligrosos
        $value = preg_replace('/javascript:/i', '', $value);
        $value = preg_replace('/vbscript:/i', '', $value);
        
        // Eliminar todas las etiquetas HTML
        $value = strip_tags($value);
        
        return trim($value);
    }

    /**
     * Sanitiza un array de datos
     */
    public static function cleanArray($data)
    {
        $cleaned = [];
        
        foreach ($data as $key => $value) {
            $cleaned[$key] = self::clean($value);
        }
        
        return $cleaned;
    }

    /**
     * Sanitiza datos de entrada (POST, GET, etc.)
     */
    public static function input($data, $except = ['password', 'password_confirmation'])
    {
        $cleaned = [];
        
        foreach ($data as $key => $value) {
            // No sanitizar campos sensibles como passwords
            if (in_array($key, $except)) {
                $cleaned[$key] = $value;
            } else {
                $cleaned[$key] = self::clean($value);
            }
        }
        
        return $cleaned;
    }

    /**
     * Sanitiza un email
     */
    public static function email($email)
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitiza una URL
     */
    public static function url($url)
    {
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }

    /**
     * Sanitiza un número entero
     */
    public static function int($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitiza un número flotante
     */
    public static function float($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    /**
     * Sanitiza un nombre de archivo
     */
    public static function filename($filename)
    {
        // Eliminar caracteres peligrosos
        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $filename);
        
        // Evitar ../ path traversal
        $filename = str_replace('..', '', $filename);
        
        return $filename;
    }
}
