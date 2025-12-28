<?php
/**
 * Endpoints para gestión de documentos
 * - GET /documents?patient_id=
 * - POST /documents (multipart: file, patient_id, title)
 * - GET /documents/{id}/download
 * - POST /documents/{id}/sign (optional signature file or metadata)
 */

Auth::requireAuth();
$user = Auth::getCurrentUser();
$db = Database::getInstance();

// List documents by patient
if ($method === 'GET' && !$id) {
    $patientId = $_GET['patient_id'] ?? null;
    if (!$patientId) {
        Response::validationError(['patient_id' => 'Se requiere patient_id']);
    }

    // Authorization: admin/staff/doctor or the patient themself
    if (!in_array($user['role'], ['admin','staff','doctor']) && intval($user['user_id']) !== intval($patientId)) {
        Response::forbidden('No tienes permisos para ver estos documentos');
    }

    $docs = $db->fetchAll('SELECT * FROM documents WHERE patient_id = ? ORDER BY created_at DESC', [$patientId]);
    Response::success(['data' => $docs, 'total' => count($docs)]);
}

// Upload document (multipart/form-data)
if ($method === 'POST' && !$id) {
    // Expect multipart/form-data
    if (empty($_FILES['file'])) {
        Response::validationError(['file' => 'Archivo requerido (name=file)']);
    }

    $postPatientId = $_POST['patient_id'] ?? null;
    if (!$postPatientId) {
        Response::validationError(['patient_id' => 'Se requiere patient_id']);
    }

    // Authorization
    if (!in_array($user['role'], ['admin','staff','doctor']) && intval($user['user_id']) !== intval($postPatientId)) {
        Response::forbidden('No tienes permisos para subir documentos para este paciente');
    }

    try {
        require_once __DIR__ . '/../../core/Document.php';
        $meta = Document::storeUploadedFile($postPatientId, $_FILES['file']);

        $db->execute('INSERT INTO documents (patient_id, uploaded_by, title, filename, original_filename, mime, size, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())', [
            $postPatientId,
            $user['user_id'] ?? null,
            $_POST['title'] ?? null,
            $meta['filename'],
            $meta['original_filename'],
            $meta['mime'],
            $meta['size']
        ]);

        $docId = $db->lastInsertId();

        if (class_exists('Audit')) {
            Audit::log('create_document', 'document', $docId, ['patient_id' => $postPatientId, 'uploaded_by' => $user['user_id'] ?? null]);
        }

        $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$docId]);
        $doc['url'] = '/uploads/documents/' . intval($postPatientId) . '/' . $doc['filename'];

        Response::success($doc, 'Documento subido correctamente', 201);
    } catch (Exception $e) {
        error_log('Upload document error: ' . $e->getMessage());
        Response::error('Error al subir documento');
    }
}

// Download (or return URL) for a document
if ($id && $action === 'download' && $method === 'GET') {
    $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$doc) {
        Response::notFound('Documento no encontrado');
    }

    // Authorization
    if (!in_array($user['role'], ['admin','staff','doctor']) && intval($user['user_id']) !== intval($doc['patient_id'])) {
        Response::forbidden('No tienes permisos para descargar este documento');
    }

    $url = '/uploads/documents/' . intval($doc['patient_id']) . '/' . $doc['filename'];
    Response::success(['url' => $url, 'filename' => $doc['original_filename']]);
}

// Serve file inline (for viewer) - streams the file with appropriate headers
if ($id && $action === 'file' && $method === 'GET') {
    $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$doc) {
        Response::notFound('Documento no encontrado');
    }

    // Authorization
    if (!in_array($user['role'], ['admin','staff','doctor']) && intval($user['user_id']) !== intval($doc['patient_id'])) {
        Response::forbidden('No tienes permisos para ver este documento');
    }

    $filePath = __DIR__ . '/../../uploads/documents/' . intval($doc['patient_id']) . '/' . $doc['filename'];
    if (!file_exists($filePath)) {
        Response::notFound('Archivo no encontrado en el servidor');
    }

    $mime = $doc['mime'] ?? mime_content_type($filePath);
    // Send headers for inline display
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . basename($doc['original_filename'] ?? $doc['filename']) . '"');
    // Cache headers
    header('Cache-Control: public, max-age=86400');

    // Stream file
    readfile($filePath);
    exit;
}

// Preview page (HTML) that embeds the inline file URL
if ($id && $action === 'view' && $method === 'GET') {
    $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$doc) {
        Response::notFound('Documento no encontrado');
    }

    // Authorization
    if (!in_array($user['role'], ['admin','staff','doctor']) && intval($user['user_id']) !== intval($doc['patient_id'])) {
        Response::forbidden('No tienes permisos para ver este documento');
    }

    $mime = $doc['mime'] ?? 'application/octet-stream';
    $fileUrl = '/documents/' . intval($id) . '/file';

    // Minimal HTML viewer
    $title = htmlspecialchars($doc['title'] ?? $doc['original_filename'] ?? 'Documento');
    echo "<!doctype html><html lang='es'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>Previsualizar - {$title}</title>";
    echo "<style>body{margin:0;font-family:Arial,Helvetica,sans-serif}.toolbar{padding:8px;background:#f4f4f4;border-bottom:1px solid #e0e0e0} .viewer{width:100%;height:calc(100vh - 50px);border:0}</style>";
    echo "</head><body>";
    echo "<div class='toolbar'><strong>{$title}</strong> - <a href='/documents/{$id}/download' target='_blank'>Descargar</a></div>";

    if (strpos($mime, 'pdf') !== false) {
        echo "<iframe class='viewer' src='{$fileUrl}'></iframe>";
    } elseif (strpos($mime, 'image/') === 0) {
        echo "<div style='padding:10px;text-align:center'><img src='{$fileUrl}' style='max-width:100%;height:auto' alt='Documento' /></div>";
    } else {
        // For other types, attempt to embed via iframe or show download link
        echo "<iframe class='viewer' src='{$fileUrl}'></iframe>";
    }

    echo "</body></html>";
    exit;
}

// Sign a document
if ($id && $action === 'sign' && $method === 'POST') {
    $doc = $db->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
    if (!$doc) {
        Response::notFound('Documento no encontrado');
    }

    // Only certain roles or the patient themself can sign
    if (!in_array($user['role'], ['admin','staff','doctor','patient'])) {
        Response::forbidden('No tienes permisos para firmar este documento');
    }

    // Optionally accept a signature file (e.g., PNG) in $_FILES['signature']
    $signatureFile = null;
    if (!empty($_FILES['signature'])) {
        try {
            require_once __DIR__ . '/../../core/Document.php';
            $sigMeta = Document::storeUploadedFile($doc['patient_id'], $_FILES['signature']);
            $signatureFile = $sigMeta['filename'];
        } catch (Exception $e) {
            error_log('Signature upload failed: ' . $e->getMessage());
            Response::error('No se pudo subir la firma');
        }
    }

    // Insert signature record
    $db->execute('INSERT INTO document_signatures (document_id, signed_by, signature_method, signature_file, meta, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [
        $id,
        $user['user_id'] ?? null,
        $_POST['method'] ?? 'manual',
        $signatureFile,
        json_encode($_POST['meta'] ?? [])
    ]);

    $sigId = $db->lastInsertId();

    // Mark document as signed
    $db->execute('UPDATE documents SET signed = 1 WHERE id = ?', [$id]);

    if (class_exists('Audit')) {
        Audit::log('sign_document', 'document', $id, ['signed_by' => $user['user_id'] ?? null, 'signature_id' => $sigId]);
    }

    $signature = $db->fetchOne('SELECT * FROM document_signatures WHERE id = ?', [$sigId]);
    Response::success($signature, 'Documento firmado');
}

Response::error('Método no permitido', 405);
