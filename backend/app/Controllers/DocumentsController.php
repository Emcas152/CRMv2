<?php
namespace App\Controllers;

class DocumentsController
{
    private const API_BASE = '/api/v1';

    private function canAccessPatient($user, int $patientId, $db): bool
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['superadmin', 'admin', 'staff', 'doctor'], true)) {
            return true;
        }

        if ($role !== 'patient') {
            return false;
        }

        $patient = $db->fetchOne('SELECT id, user_id, email FROM patients WHERE id = ? LIMIT 1', [$patientId]);
        if (!$patient) {
            return false;
        }

        $owns = (!empty($patient['user_id']) && intval($patient['user_id']) === intval($user['user_id'] ?? 0))
            || (!empty($patient['email']) && ($user['email'] ?? null) === $patient['email']);
        return $owns;
    }

    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Document.php';
        require_once __DIR__ . '/../Core/Crypto.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        try {
            $method = $_SERVER['REQUEST_METHOD'];

            // Public, signed download (no JWT required)
            if ($id && $action === 'signed-file' && $method === 'GET') {
                return $this->signedFile($id);
            }

            \App\Core\Auth::requireAuth();

            if ($method === 'GET' && !$id) {
                return $this->index();
            }

            if ($method === 'POST' && !$id) {
                return $this->upload();
            }

            if ($id && $action === 'download' && $method === 'GET') {
                return $this->download($id);
            }

            if ($id && $action === 'file' && $method === 'GET') {
                return $this->file($id);
            }

            if ($id && $action === 'view' && $method === 'GET') {
                return $this->view($id);
            }

            if ($id && $action === 'sign' && $method === 'POST') {
                return $this->sign($id);
            }

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function index()
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $patientId = $_GET['patient_id'] ?? null;
        if (!$patientId) {
            \App\Core\Response::validationError(['patient_id' => 'Se requiere patient_id']);
        }

        if (!$this->canAccessPatient($user, intval($patientId), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para ver estos documentos');
        }

        $docs = $db->fetchAll('SELECT * FROM documents WHERE patient_id = ? ORDER BY created_at DESC', [$patientId]);
        \App\Core\Response::success(['data' => $docs, 'total' => count($docs)]);
    }

    private function upload()
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        if (empty($_FILES['file'])) {
            \App\Core\Response::validationError(['file' => 'Archivo requerido (name=file)']);
        }

        $postPatientId = $_POST['patient_id'] ?? null;
        if (!$postPatientId) {
            \App\Core\Response::validationError(['patient_id' => 'Se requiere patient_id']);
        }

        if (!$this->canAccessPatient($user, intval($postPatientId), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para subir documentos para este paciente');
        }

        try {
            $meta = \App\Core\Document::storeUploadedFile($postPatientId, $_FILES['file']);

            $db->execute(
                'INSERT INTO documents (patient_id, uploaded_by, title, filename, original_filename, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $postPatientId,
                    $user['user_id'] ?? null,
                    $_POST['title'] ?? null,
                    $meta['filename'],
                    $meta['original_filename'],
                    $meta['mime'],
                    $meta['size'],
                ]
            );

            $docId = $db->lastInsertId();

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_document', 'document', $docId, ['patient_id' => $postPatientId, 'uploaded_by' => $user['user_id'] ?? null]);
            }

            $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$docId]);
            $doc['download_url'] = self::API_BASE . '/documents/' . intval($docId) . '/download';
            $doc['file_url'] = self::API_BASE . '/documents/' . intval($docId) . '/file';
            $doc['view_url'] = self::API_BASE . '/documents/' . intval($docId) . '/view';

            \App\Core\Response::success($doc, 'Documento subido correctamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::exception('Error al subir documento', $e);
        }
    }

    private function download($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) {
            \App\Core\Response::notFound('Documento no encontrado');
        }

        if (!$this->canAccessPatient($user, intval($doc['patient_id']), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para descargar este documento');
        }

        // If we already have a non-expired signed URL, reuse it.
        $now = new \DateTimeImmutable('now');
        if (!empty($doc['signed_url']) && !empty($doc['signed_url_expires_at'])) {
            try {
                $exp = new \DateTimeImmutable($doc['signed_url_expires_at']);
                if ($exp->getTimestamp() > ($now->getTimestamp() + 30)) {
                    \App\Core\Response::success(['url' => $doc['signed_url'], 'filename' => $doc['original_filename']]);
                }
            } catch (\Throwable $e) {
                // ignore and rotate
            }
        }

        $config = require __DIR__ . '/../../config/app.php';
        $ttl = intval($config['signed_url_ttl_seconds'] ?? 600);
        if ($ttl < 60) $ttl = 60;
        if ($ttl > 86400) $ttl = 86400;

        $expires = $now->getTimestamp() + $ttl;
        $payload = 'doc:' . intval($id) . '|file:' . ($doc['filename'] ?? '') . '|exp:' . $expires;
        $signature = hash_hmac('sha256', $payload, (string)($config['secret_key'] ?? 'change-me'));

        $baseUrl = rtrim((string)($config['app_url'] ?? ''), '/');
        $signedUrl = $baseUrl . self::API_BASE . '/documents/' . intval($id) . '/signed-file?expires=' . $expires . '&signature=' . $signature;

        // Persist for reuse / audit
        $expiresAt = (new \DateTimeImmutable('@' . $expires))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        try {
            $db->execute('UPDATE documents SET signed_url = ?, signed_url_expires_at = ? WHERE id = ?', [$signedUrl, $expiresAt, $id]);
        } catch (\Throwable $e) {
            // If columns don't exist yet, still return the URL.
        }

        \App\Core\Response::success(['url' => $signedUrl, 'filename' => $doc['original_filename']]);
    }

    private function file($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) {
            \App\Core\Response::notFound('Documento no encontrado');
        }

        if (!$this->canAccessPatient($user, intval($doc['patient_id']), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para ver este documento');
        }

        $config = require __DIR__ . '/../../config/app.php';
        $uploadRoot = $config['upload_path'] ?? (__DIR__ . '/../../uploads');
        $filePath = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/documents/' . intval($doc['patient_id']) . '/' . $doc['filename'];

        if (!file_exists($filePath)) {
            \App\Core\Response::notFound('Archivo no encontrado en el servidor');
        }

        $mime = $doc['mime'] ?? mime_content_type($filePath);
        $filename = basename($doc['original_filename'] ?? $doc['filename']);

        $bytes = \App\Core\Document::readStoredFile($filePath);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0');

        echo $bytes;
        exit;
    }

    private function signedFile($id)
    {
        $db = \App\Core\Database::getInstance();

        $expires = isset($_GET['expires']) ? intval($_GET['expires']) : 0;
        $sig = isset($_GET['signature']) ? (string)$_GET['signature'] : '';
        if ($expires <= 0 || $sig === '') {
            \App\Core\Response::unauthorized('Firma inválida');
        }

        if ($expires < time()) {
            \App\Core\Response::unauthorized('URL expirada');
        }

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) {
            \App\Core\Response::notFound('Documento no encontrado');
        }

        $config = require __DIR__ . '/../../config/app.php';
        $payload = 'doc:' . intval($id) . '|file:' . ($doc['filename'] ?? '') . '|exp:' . $expires;
        $expected = hash_hmac('sha256', $payload, (string)($config['secret_key'] ?? 'change-me'));
        if (!hash_equals($expected, $sig)) {
            \App\Core\Response::unauthorized('Firma inválida');
        }

        $uploadRoot = $config['upload_path'] ?? (__DIR__ . '/../../uploads');
        $filePath = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/documents/' . intval($doc['patient_id']) . '/' . $doc['filename'];
        if (!file_exists($filePath)) {
            \App\Core\Response::notFound('Archivo no encontrado en el servidor');
        }

        $mime = $doc['mime'] ?? mime_content_type($filePath);
        $filename = basename($doc['original_filename'] ?? $doc['filename']);
        $bytes = \App\Core\Document::readStoredFile($filePath);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0');

        echo $bytes;
        exit;
    }

    private function view($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) {
            \App\Core\Response::notFound('Documento no encontrado');
        }

        if (!$this->canAccessPatient($user, intval($doc['patient_id']), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para ver este documento');
        }

        $mime = $doc['mime'] ?? 'application/octet-stream';
        $fileUrl = self::API_BASE . '/documents/' . intval($id) . '/file';
        $downloadUrl = self::API_BASE . '/documents/' . intval($id) . '/download';

        $title = htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? 'Documento');

        header('Content-Type: text/html; charset=utf-8');

        echo "<!doctype html><html lang='es'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
        echo "<title>Previsualizar - {$title}</title>";
        echo "<style>body{margin:0;font-family:Arial,Helvetica,sans-serif}.toolbar{padding:8px;background:#f4f4f4;border-bottom:1px solid #e0e0e0}.viewer{width:100%;height:calc(100vh - 50px);border:0}</style>";
        echo "</head><body>";
        echo "<div class='toolbar'><strong>{$title}</strong> - <a href='{$downloadUrl}' target='_blank'>Descargar</a></div>";

        if (strpos($mime, 'pdf') !== false) {
            echo "<iframe class='viewer' src='{$fileUrl}'></iframe>";
        } elseif (strpos($mime, 'image/') === 0) {
            echo "<div style='padding:10px;text-align:center'><img src='{$fileUrl}' style='max-width:100%;height:auto' alt='Documento' /></div>";
        } else {
            echo "<iframe class='viewer' src='{$fileUrl}'></iframe>";
        }

        echo "</body></html>";
        exit;
    }

    private function sign($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) {
            \App\Core\Response::notFound('Documento no encontrado');
        }

        if (!$this->canAccessPatient($user, intval($doc['patient_id']), $db)) {
            \App\Core\Response::forbidden('No tienes permisos para firmar este documento');
        }

        $signatureFile = null;
        if (!empty($_FILES['signature'])) {
            try {
                $sigMeta = \App\Core\Document::storeUploadedFile($doc['patient_id'], $_FILES['signature']);
                $signatureFile = $sigMeta['filename'];
            } catch (\Exception $e) {
                error_log('Signature upload failed: ' . $e->getMessage());
                \App\Core\Response::error('No se pudo subir la firma');
            }
        }

        $meta = $_POST['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $db->execute(
            'INSERT INTO document_signatures (document_id, signed_by, signature_method, signature_file, meta, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $id,
                $user['user_id'] ?? null,
                $_POST['method'] ?? 'manual',
                $signatureFile,
                json_encode($meta),
            ]
        );

        $sigId = $db->lastInsertId();
        $db->execute('UPDATE documents SET signed = 1 WHERE id = ?', [$id]);

        if (class_exists('\\App\\Core\\Audit')) {
            \App\Core\Audit::log('sign_document', 'document', $id, ['signed_by' => $user['user_id'] ?? null, 'signature_id' => $sigId]);
        }

        $signature = $db->fetchOne('SELECT * FROM document_signatures WHERE id = ?', [$sigId]);
        \App\Core\Response::success($signature, 'Documento firmado');
    }
}
