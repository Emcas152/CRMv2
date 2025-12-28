<?php
namespace App\Core;

use Exception;

/**
 * Helper para guardar y manipular documentos.
 */
class Document
{
    public static function storeUploadedFile($patientId, $file)
    {
        $config = require __DIR__ . '/../../config/app.php';
        $uploadRoot = $config['upload_path'] ?? (__DIR__ . '/../../uploads');

        $targetDir = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/documents/' . intval($patientId);
        @mkdir($targetDir, 0755, true);

        $originalName = $file['name'] ?? 'file';
        $tmp = $file['tmp_name'] ?? null;

        if (!$tmp || !is_file($tmp)) {
            throw new Exception('Archivo subido inválido');
        }

        $mime = $file['type'] ?? mime_content_type($tmp);
        $size = $file['size'] ?? filesize($tmp);

        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '.' . ($ext ?: 'bin');
        $dest = $targetDir . '/' . $filename;

        // Optional: encryption at rest (AES-256-GCM). When enabled, the stored file
        // contains an ENCv1 header and encrypted bytes.
        if (!empty($config['documents_encrypt_at_rest'])) {
            require_once __DIR__ . '/Crypto.php';
            $plain = @file_get_contents($tmp);
            if ($plain === false) {
                throw new Exception('No se pudo leer el archivo subido');
            }

            $encrypted = Crypto::encryptBytes($plain);
            if (@file_put_contents($dest, $encrypted) === false) {
                throw new Exception('No se pudo guardar el archivo cifrado');
            }
        } else {
            if (!@move_uploaded_file($tmp, $dest)) {
                if (!@copy($tmp, $dest)) {
                    throw new Exception('No se pudo mover el archivo subido');
                }
            }
        }

        return [
            'filename' => $filename,
            'original_filename' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'path' => $dest,
        ];
    }

    public static function readStoredFile(string $path): string
    {
        if (!is_file($path)) {
            throw new Exception('Archivo no encontrado');
        }

        $payload = @file_get_contents($path);
        if ($payload === false) {
            throw new Exception('No se pudo leer el archivo');
        }

        $config = require __DIR__ . '/../../config/app.php';
        if (!empty($config['documents_encrypt_at_rest'])) {
            require_once __DIR__ . '/Crypto.php';
            return Crypto::decryptBytes($payload);
        }

        return $payload;
    }
}
