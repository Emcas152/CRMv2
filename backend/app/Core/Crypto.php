<?php
namespace App\Core;

class Crypto
{
    private const HEADER_PREFIX = 'ENCv1:';

    public static function encryptionEnabled(): bool
    {
        $config = require __DIR__ . '/../../config/app.php';
        return !empty($config['documents_encrypt_at_rest']);
    }

    /**
     * Derive a 32-byte key from app secret.
     */
    public static function key(): string
    {
        $config = require __DIR__ . '/../../config/app.php';
        $secret = (string)($config['secret_key'] ?? '');
        // 32 bytes
        return hash('sha256', $secret, true);
    }

    public static function encryptBytes(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12); // recommended for GCM
        $tag = '';

        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar el contenido');
        }

        $header = self::HEADER_PREFIX . base64_encode($iv) . ':' . base64_encode($tag) . "\n";
        return $header . $cipher;
    }

    public static function decryptBytes(string $stored): string
    {
        if (!self::isEncryptedPayload($stored)) {
            return $stored;
        }

        $pos = strpos($stored, "\n");
        if ($pos === false) {
            throw new \RuntimeException('Formato de cifrado inválido');
        }

        $header = substr($stored, 0, $pos);
        $cipher = substr($stored, $pos + 1);

        $parts = explode(':', $header);
        // Expected: ["ENCv1", b64(iv), b64(tag)]
        if (count($parts) !== 3 || ($parts[0] . ':') !== self::HEADER_PREFIX) {
            throw new \RuntimeException('Encabezado de cifrado inválido');
        }

        $iv = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        if ($iv === false || $tag === false) {
            throw new \RuntimeException('Encabezado de cifrado corrupto');
        }

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar el contenido');
        }

        return $plain;
    }

    public static function isEncryptedFile(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;

        $line = @fgets($fh);
        @fclose($fh);

        if ($line === false) return false;
        return strncmp($line, self::HEADER_PREFIX, strlen(self::HEADER_PREFIX)) === 0;
    }

    public static function isEncryptedPayload(string $payload): bool
    {
        return strncmp($payload, self::HEADER_PREFIX, strlen(self::HEADER_PREFIX)) === 0;
    }
    
    /**
     * Encripta un valor de campo (string, número, etc)
     * Para usar en campos sensibles como price, nit, phone
     * 
     * @param mixed $value Valor a encriptar
     * @return string Valor encriptado (almacenar en LONGBLOB)
     */
    public static function encryptField($value): string
    {
        if ($value === null) {
            return '';
        }
        
        $stringValue = (string) $value;
        
        try {
            return self::encryptBytes($stringValue);
        } catch (\Exception $e) {
            error_log("Error encrypting field: " . $e->getMessage());
            throw new \RuntimeException("Error al encriptar campo: " . $e->getMessage());
        }
    }
    
    /**
     * Desencripta un valor de campo
     * 
     * @param string $encrypted Valor encriptado
     * @return string Valor original desencriptado
     */
    public static function decryptField(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }
        
        try {
            return self::decryptBytes($encrypted);
        } catch (\Exception $e) {
            error_log("Error decrypting field: " . $e->getMessage());
            throw new \RuntimeException("Error al desencriptar campo: " . $e->getMessage());
        }
    }
    
    /**
     * Genera un hash SHA256 para búsqueda sin descifrar
     * Usado para campos como NIT, email, teléfono
     * 
     * @param string $value Valor a hashear
     * @return string SHA256 hash (64 caracteres hex)
     */
    public static function hashField(string $value): string
    {
        return hash('sha256', trim($value));
    }
    
    /**
     * Verifica si un valor coincide con su hash
     * (para búsquedas y validaciones)
     * 
     * @param string $value Valor original
     * @param string $hash Hash para comparar
     * @return bool True si coinciden
     */
    public static function verifyHashField(string $value, string $hash): bool
    {
        return hash_equals(self::hashField($value), $hash);
    }
}
