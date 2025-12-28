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
}
