<?php
/**
 * Helper para guardar y manipular documentos
 */
class Document
{
    public static function storeUploadedFile($patientId, $file)
    {
        $config = require __DIR__ . '/../config/app.php';
        $uploadRoot = $config['upload_path'] ?? __DIR__ . '/../uploads';

        $targetDir = rtrim($uploadRoot, '/') . '/documents/' . intval($patientId);
        @mkdir($targetDir, 0755, true);

        $originalName = $file['name'];
        $tmp = $file['tmp_name'];
        $mime = $file['type'] ?? mime_content_type($tmp);
        $size = $file['size'] ?? filesize($tmp);

        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '.' . ($ext ?: 'bin');
        $dest = $targetDir . '/' . $filename;

        if (!move_uploaded_file($tmp, $dest)) {
            // Try copy as fallback
            if (!@copy($tmp, $dest)) {
                throw new Exception('No se pudo mover el archivo subido');
            }
        }

        return [
            'filename' => $filename,
            'original_filename' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'path' => $dest,
            'url' => '/uploads/documents/' . intval($patientId) . '/' . $filename
        ];
    }
}
