<?php
namespace App\Controllers;

use App\Core\Request;
class PatientsController {
    protected static function initCore() {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/QR.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Sanitizer.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
        require_once __DIR__ . '/../Core/Mailer.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/FieldEncryption.php';
    }

    public function handle($id = null, $action = null) {
        self::initCore();
        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        // Actions
        if ($id && $action === 'qr' && $method === 'GET') {
            return $this->getQr($id);
        }

        if ($id && $action === 'upload-photo' && $method === 'POST') {
            return $this->uploadPhoto($id, $input);
        }

        if ($method === 'GET' && !$id) {
            return $this->index();
        }

        if ($method === 'GET' && $id) {
            return $this->show($id);
        }

        if ($method === 'POST' && !$id) {
            return $this->store($input);
        }

        if ($method === 'PUT' && $id) {
            return $this->update($id, $input);
        }

        if ($method === 'DELETE' && $id) {
            return $this->destroy($id);
        }

        if ($method === 'POST' && $id && $action === 'loyalty-add') {
            return $this->loyaltyAdd($id, $input);
        }

        if ($method === 'POST' && $id && $action === 'loyalty-redeem') {
            return $this->loyaltyRedeem($id, $input);
        }

        \App\Core\Response::error('Método no permitido', 405);
    }

    private function index() {
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        // Operational access (staff/doctor/admin/superadmin). Patients can only see themselves.
        if (($user['role'] ?? null) === 'patient') {
            $own = $db->fetchOne('SELECT * FROM patients WHERE user_id = ? OR email = ? LIMIT 1', [
                intval($user['user_id'] ?? 0),
                (string)($user['email'] ?? ''),
            ]);
            $data = $own ? [$own] : [];
            
            // Desencriptar
            foreach ($data as &$patient) {
                if (!empty($patient['email_encrypted'])) {
                    try { $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']); } catch (\Exception $e) {}
                }
                if (!empty($patient['phone_encrypted'])) {
                    try { $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']); } catch (\Exception $e) {}
                }
            }
            
            \App\Core\Response::success(['data' => $data, 'total' => count($data)]);
        }

        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para ver pacientes');

        $query = 'SELECT * FROM patients WHERE 1=1';
        $params = [];

        if ($user['role'] === 'doctor') {
            $staffMember = $db->fetchOne('SELECT id FROM staff_members WHERE user_id = ?', [$user['user_id']]);
            if ($staffMember) {
                $query .= ' AND id IN (SELECT DISTINCT patient_id FROM appointments WHERE staff_member_id = ?)';
                $params[] = $staffMember['id'];
            } else {
                \App\Core\Response::success(['data' => [], 'total' => 0]);
            }
        }

        if (isset($_GET['search'])) {
            $search = '%' . $_GET['search'] . '%';
            $query .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $params[] = $search; $params[] = $search; $params[] = $search;
        }

        if (isset($_GET['date_from'])) { $query .= ' AND DATE(created_at) >= ?'; $params[] = $_GET['date_from']; }
        if (isset($_GET['date_to'])) { $query .= ' AND DATE(created_at) <= ?'; $params[] = $_GET['date_to']; }
        if (isset($_GET['birthday_month'])) { $query .= ' AND MONTH(birthday) = ?'; $params[] = $_GET['birthday_month']; }

        $query .= ' ORDER BY created_at DESC LIMIT 20';
        $patients = $db->fetchAll($query, $params);
        
        // Desencriptar
        foreach ($patients as &$patient) {
            if (!empty($patient['email_encrypted'])) {
                try { $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']); } catch (\Exception $e) {}
            }
            if (!empty($patient['phone_encrypted'])) {
                try { $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']); } catch (\Exception $e) {}
            }
        }
        
        \App\Core\Response::success(['data' => $patients, 'total' => count($patients)]);
    }

    private function show($id) {
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        if (!$patient) { \App\Core\Response::notFound('Paciente no encontrado'); }

        // Patients can only access their own record
        if (($user['role'] ?? null) === 'patient') {
            $owns = (isset($patient['user_id']) && intval($patient['user_id']) === intval($user['user_id'] ?? 0))
                || (!empty($patient['email']) && ($user['email'] ?? null) === $patient['email']);
            if (!$owns) {
                \App\Core\Response::forbidden('No tienes acceso a este paciente');
            }
        } else {
            \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para ver pacientes');
        }

        if ($user['role'] === 'doctor') {
            $staffMember = $db->fetchOne('SELECT id FROM staff_members WHERE user_id = ?', [$user['user_id']]);
            if ($staffMember) {
                $hasAccess = $db->fetchOne('SELECT id FROM appointments WHERE patient_id = ? AND staff_member_id = ? LIMIT 1', [$id, $staffMember['id']]);
                if (!$hasAccess) { \App\Core\Response::forbidden('No tienes acceso a este paciente'); }
            } else { \App\Core\Response::forbidden('No tienes acceso a este paciente'); }
        }

        // Desencriptar email y phone si existen
        if (!empty($patient['email_encrypted'])) {
            try {
                $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']);
            } catch (\Exception $e) {
                error_log("Error desencriptando email del paciente {$id}: " . $e->getMessage());
            }
        }

        if (!empty($patient['phone_encrypted'])) {
            try {
                $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']);
            } catch (\Exception $e) {
                error_log("Error desencriptando phone del paciente {$id}: " . $e->getMessage());
            }
        }

        \App\Core\Response::success($patient);
    }

    private function getQr($id)
    {
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        if (!$patient) {
            \App\Core\Response::notFound('Paciente no encontrado');
        }

        // Patients can only access their own QR
        if (($user['role'] ?? null) === 'patient') {
            if (!isset($patient['user_id']) || intval($patient['user_id']) !== intval($user['user_id'] ?? 0)) {
                if (empty($patient['email']) || ($user['email'] ?? null) !== $patient['email']) {
                    \App\Core\Response::forbidden('No tienes permisos para ver el QR de este paciente');
                }
            }
        }

        // Best-effort: ensure qr_code column exists (useful for DBs created outside install.sql)
        try { $db->execute('ALTER TABLE patients ADD COLUMN qr_code VARCHAR(255) NULL', []); } catch (\Throwable $e) { /* ignore */ }

        if (empty($patient['qr_code'])) {
            $code = \App\Core\QR::generateCode();
            $db->execute('UPDATE patients SET qr_code = ?, updated_at = NOW() WHERE id = ?', [$code, $id]);
            $patient['qr_code'] = $code;
        }

        $qrUrl = \App\Core\QR::qrImageUrl($patient['qr_code']);

        if (class_exists('\\App\\Core\\Audit')) {
            \App\Core\Audit::log('get_qr', 'patient', $id, ['qr' => $patient['qr_code']]);
        }

        \App\Core\Response::success(['qr_code' => $patient['qr_code'], 'qr_url' => $qrUrl]);
    }

    private function store($input) {
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        if (!in_array($user['role'], ['superadmin', 'admin', 'doctor', 'staff'], true)) {
            \App\Core\Response::forbidden('No tienes permisos para crear pacientes');
        }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'birthday' => 'nullable|date',
            'address' => 'nullable|string|max:500',
            'nit' => 'nullable|string|max:100'
        ]);

        try { $validator->validate(); } catch (\Exception $e) { \App\Core\Response::validationError([$e->getMessage()]); }

        $existing = $db->fetchOne('SELECT id FROM patients WHERE email = ? AND id != ?', [$input['email'], $id]);
        if ($existing) { \App\Core\Response::error('El email ya está registrado', 422); }

        try {
            try { $db->execute("ALTER TABLE patients ADD COLUMN IF NOT EXISTS nit VARCHAR(100) NULL", []); } catch (\Exception $e) {}
            
            // Validar y encriptar email y phone
            $encryptedData = [];
            if (isset($input['email'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['email'], \App\Core\FieldEncryption::TYPE_EMAIL)) {
                    \App\Core\Response::validationError(['email' => 'Email inválido']);
                }
                $encryptedData['email_encrypted'] = \App\Core\FieldEncryption::encryptValue($input['email']);
                $encryptedData['email_hash'] = \App\Core\FieldEncryption::hashValue($input['email']);
            }
            
            if (isset($input['phone'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['phone'], \App\Core\FieldEncryption::TYPE_PHONE)) {
                    \App\Core\Response::validationError(['phone' => 'Teléfono inválido']);
                }
                $encryptedData['phone_encrypted'] = \App\Core\FieldEncryption::encryptValue($input['phone']);
                $encryptedData['phone_hash'] = \App\Core\FieldEncryption::hashValue($input['phone']);
            }
            
            $db->execute('INSERT INTO patients (name, email, email_encrypted, email_hash, phone, phone_encrypted, phone_hash, birthday, address, nit, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())', [
                $input['name'], 
                $input['email'],
                $encryptedData['email_encrypted'] ?? null,
                $encryptedData['email_hash'] ?? null,
                $input['phone'] ?? null, 
                $encryptedData['phone_encrypted'] ?? null,
                $encryptedData['phone_hash'] ?? null,
                $input['birthday'] ?? null, 
                $input['address'] ?? null, 
                $input['nit'] ?? null
            ]);
            $patientId = $db->lastInsertId();
            $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patientId]);
            
            // Desencriptar para respuesta
            if (!empty($patient['email_encrypted'])) {
                $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']);
            }
            if (!empty($patient['phone_encrypted'])) {
                $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']);
            }
            
            if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('create_patient','patient',$patientId,['name'=>$input['name'],'email'=>$input['email']]); }
            \App\Core\Response::success($patient, 'Paciente creado exitosamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear paciente', $e);
        }
    }

    private function update($id, $input) {
        // Staff/doctor can operate patients; superadmin bypasses. (Delete remains admin-only.)
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para editar pacientes');
        $db = \App\Core\Database::getInstance();

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        if (!$patient) { \App\Core\Response::notFound('Paciente no encontrado'); }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'string|max:255', 'email' => 'email|max:255', 'phone' => 'string|max:20', 'birthday' => 'date', 'address' => 'string|max:500', 'nit' => 'nullable|string|max:100'
        ]);

        try { $validator->validate(); } catch (\Exception $e) { \App\Core\Response::validationError([$e->getMessage()]); }

        try {
            $updates = []; $params = [];
            
            // Encriptar email si se proporciona
            if (isset($input['email'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['email'], \App\Core\FieldEncryption::TYPE_EMAIL)) {
                    \App\Core\Response::validationError(['email' => 'Email inválido']);
                }
                $updates[] = 'email = ?';
                $params[] = $input['email'];
                $updates[] = 'email_encrypted = ?';
                $params[] = \App\Core\FieldEncryption::encryptValue($input['email']);
                $updates[] = 'email_hash = ?';
                $params[] = \App\Core\FieldEncryption::hashValue($input['email']);
            }
            
            // Encriptar phone si se proporciona
            if (isset($input['phone'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['phone'], \App\Core\FieldEncryption::TYPE_PHONE)) {
                    \App\Core\Response::validationError(['phone' => 'Teléfono inválido']);
                }
                $updates[] = 'phone = ?';
                $params[] = $input['phone'];
                $updates[] = 'phone_encrypted = ?';
                $params[] = \App\Core\FieldEncryption::encryptValue($input['phone']);
                $updates[] = 'phone_hash = ?';
                $params[] = \App\Core\FieldEncryption::hashValue($input['phone']);
            }
            
            // Procesar otros campos
            foreach (['name','birthday','address','nit'] as $f) {
                if (isset($input[$f])) { $updates[] = "$f = ?"; $params[] = $input[$f]; }
            }
            
            if (empty($updates)) {
                \App\Core\Response::success($patient, 'Sin cambios');
            }
            
            $updates[] = 'updated_at = NOW()';
            $params[] = $id;
            $query = 'UPDATE patients SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->execute($query, $params);
            $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
            
            // Desencriptar para respuesta
            if (!empty($patient['email_encrypted'])) {
                $patient['email'] = \App\Core\FieldEncryption::decryptValue($patient['email_encrypted']);
            }
            if (!empty($patient['phone_encrypted'])) {
                $patient['phone'] = \App\Core\FieldEncryption::decryptValue($patient['phone_encrypted']);
            }
            
            if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('update_patient','patient',$id,['updates'=>$updates]); }
            \App\Core\Response::success($patient, 'Paciente actualizado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar paciente', $e);
        }
    }

    private function destroy($id) {
        // Destructive action stays restricted to admin/superadmin
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin'], 'No tienes permisos para eliminar pacientes');
        $db = \App\Core\Database::getInstance();
        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        if (!$patient) { \App\Core\Response::notFound('Paciente no encontrado'); }
        try {
            $db->execute('DELETE FROM patients WHERE id = ?', [$id]);
            if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('delete_patient','patient',$id,[]); }
            \App\Core\Response::success(null, 'Paciente eliminado exitosamente');
        } catch (\Exception $e) {
            $related = [];
            try { $related['appointments'] = intval(($db->fetchOne('SELECT COUNT(*) AS c FROM appointments WHERE patient_id = ?', [$id])['c'] ?? 0)); } catch (\Exception $ignore) {}
            try { $related['documents'] = intval(($db->fetchOne('SELECT COUNT(*) AS c FROM documents WHERE patient_id = ?', [$id])['c'] ?? 0)); } catch (\Exception $ignore) {}
            try { $related['sales'] = intval(($db->fetchOne('SELECT COUNT(*) AS c FROM sales WHERE patient_id = ?', [$id])['c'] ?? 0)); } catch (\Exception $ignore) {}
            try { $related['patient_photos'] = intval(($db->fetchOne('SELECT COUNT(*) AS c FROM patient_photos WHERE patient_id = ?', [$id])['c'] ?? 0)); } catch (\Exception $ignore) {}

            $hasRelated = false;
            foreach ($related as $count) { if ($count > 0) { $hasRelated = true; break; } }

            if ($hasRelated) {
                \App\Core\Response::exception(
                    'No se puede eliminar el paciente porque tiene registros relacionados',
                    $e,
                    409,
                    ['related' => $related]
                );
            }

            \App\Core\Response::dbException('Error interno al eliminar paciente', $e);
        }
    }

    private function loyaltyAdd($id, $input) {
        \App\Core\Auth::requireAuth();
        $validator = \App\Core\Validator::make($input, ['points' => 'required|integer|min:1|max:10000']);
        try { $validator->validate(); } catch (\Exception $e) { \App\Core\Response::validationError([$e->getMessage()]); }
        $db = \App\Core\Database::getInstance();
        try {
            $db->execute('UPDATE patients SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE id = ?', [$input['points'],$id]);
            if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('add_loyalty','patient',$id,['points'=>$input['points']]); }
            $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
            \App\Core\Response::success($patient, 'Puntos añadidos exitosamente');
        } catch (\Exception $e) { \App\Core\Response::dbException('Error al añadir puntos', $e); }
    }

    private function loyaltyRedeem($id, $input) {
        \App\Core\Auth::requireAuth();
        $validator = \App\Core\Validator::make($input, ['points' => 'required|integer|min:1|max:10000']);
        try { $validator->validate(); } catch (\Exception $e) { \App\Core\Response::validationError([$e->getMessage()]); }
        $db = \App\Core\Database::getInstance();
        $patient = $db->fetchOne('SELECT loyalty_points FROM patients WHERE id = ?', [$id]);
        if (!$patient) { \App\Core\Response::notFound('Paciente no encontrado'); }
        if ($patient['loyalty_points'] < $input['points']) { \App\Core\Response::error('Puntos insuficientes', 400); }
        try {
            $db->execute('UPDATE patients SET loyalty_points = loyalty_points - ?, updated_at = NOW() WHERE id = ?', [$input['points'],$id]);
            if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('redeem_loyalty','patient',$id,['points'=>$input['points']]); }
            $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
            \App\Core\Response::success($patient, 'Puntos canjeados exitosamente');
        } catch (\Exception $e) { \App\Core\Response::dbException('Error al canjear puntos', $e); }
    }

    private function uploadPhoto($id, $input) {
        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $db = \App\Core\Database::getInstance();

        if (!in_array($user['role'], ['superadmin','admin','staff','doctor','patient'])) { \App\Core\Response::forbidden('No tienes permisos para subir fotos'); }

        if ($user['role'] === 'patient') {
            $owner = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
            if (!$owner) { \App\Core\Response::notFound('Paciente no encontrado'); }
            if (!isset($owner['user_id']) || $owner['user_id'] != $user['user_id']) {
                if (empty($owner['email']) || $owner['email'] !== $user['email']) { \App\Core\Response::forbidden('No tienes permisos para subir fotos a este paciente'); }
            }
        }

        $type = 'before';
        if (isset($_POST['type'])) { $type = in_array($_POST['type'], ['before','after']) ? $_POST['type'] : 'before'; }
        elseif (isset($input['type'])) { $type = in_array($input['type'], ['before','after']) ? $input['type'] : 'before'; }

        $uploadsDir = __DIR__ . '/../../uploads/patients/' . $id;
        @mkdir($uploadsDir, 0755, true);

        if (!empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $origName = $_FILES['photo']['name'];
            $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = $type . '_' . time() . '.' . $ext;
            $dest = $uploadsDir . '/' . $filename;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) { \App\Core\Response::error('Error al mover el archivo subido'); }
        } else {
            if (empty($input['photo_base64'])) { \App\Core\Response::validationError(['photo' => 'Se requiere una foto (archivo multipart o base64)']); }
            $data = $input['photo_base64'];
            if (preg_match('#^data:image/([a-zA-Z]+);base64,#', $data, $m)) { $ext = $m[1]; $data = substr($data, strpos($data, ',') + 1); } else { $ext = 'jpg'; }
            $decoded = base64_decode($data);
            if ($decoded === false) { \App\Core\Response::error('Imagen inválida'); }
            $filename = $type . '_' . time() . '.' . $ext;
            $filepath = $uploadsDir . '/' . $filename;
            file_put_contents($filepath, $decoded);
        }

        $db->execute('INSERT INTO patient_photos (patient_id, type, filename, created_at) VALUES (?, ?, ?, NOW())', [$id, $type, $filename]);
        $photoId = $db->lastInsertId();
        if (class_exists('\App\Core\Audit')) { \App\Core\Audit::log('upload_photo','patient',$id,['photo_id'=>$photoId,'filename'=>$filename,'type'=>$type]); }
        \App\Core\Response::success(['id'=>$photoId,'filename'=>$filename,'url'=>'/uploads/patients/' . $id . '/' . $filename], 'Foto subida');
    }
}
