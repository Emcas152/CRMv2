<?php
/**
 * Endpoints de Pacientes
 */

Auth::requireAuth();

$user = Auth::getCurrentUser();
$db = Database::getInstance();

// Additional actions: qr generation and photo upload (base64)
if ($id && $action === 'qr' && $method === 'GET') {
    $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
    if (!$patient) {
        Response::notFound('Paciente no encontrado');
    }

    if (empty($patient['qr_code'])) {
        $code = QR::generateCode();
        $db->execute('UPDATE patients SET qr_code = ? WHERE id = ?', [$code, $id]);
        $patient['qr_code'] = $code;
    }

    $qrUrl = QR::qrImageUrl($patient['qr_code']);

    // Audit
    if (class_exists('Audit')) {
        Audit::log('get_qr', 'patient', $id, ['qr' => $patient['qr_code']]);
    }

    Response::success(['qr_code' => $patient['qr_code'], 'qr_url' => $qrUrl]);
}

if ($id && $action === 'upload-photo' && $method === 'POST') {
    // Only admin/staff/doctor or the patient themself can upload
    if (!in_array($user['role'], ['admin', 'staff', 'doctor', 'patient'])) {
        Response::forbidden('No tienes permisos para subir fotos');
    }

    // If role is patient, ensure they can only upload for their own record
    if ($user['role'] === 'patient') {
        $owner = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        if (!$owner) {
            Response::notFound('Paciente no encontrado');
        }
        if (!isset($owner['user_id']) || $owner['user_id'] != $user['user_id']) {
            if (empty($owner['email']) || $owner['email'] !== $user['email']) {
                Response::forbidden('No tienes permisos para subir fotos a este paciente');
            }
        }
    }

    $type = 'before';
    if (isset($_POST['type'])) {
        $type = in_array($_POST['type'], ['before', 'after']) ? $_POST['type'] : 'before';
    } elseif (isset($input['type'])) {
        $type = in_array($input['type'], ['before', 'after']) ? $input['type'] : 'before';
    }

    // Prefer multipart file upload (FormData) from frontend. If no file, accept base64 in JSON input.
    $uploadsDir = __DIR__ . '/../../uploads/patients/' . $id;
    @mkdir($uploadsDir, 0755, true);

    if (!empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $origName = $_FILES['photo']['name'];
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        if (!$ext) $ext = 'jpg';
        $filename = $type . '_' . time() . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            Response::error('Error al mover el archivo subido');
        }
    } else {
        // Expect base64 in input: photo_base64
        if (empty($input['photo_base64'])) {
            Response::validationError(['photo' => 'Se requiere una foto (archivo multipart o base64)']);
        }

        $data = $input['photo_base64'];
        if (preg_match('#^data:image/([a-zA-Z]+);base64,#', $data, $m)) {
            $ext = $m[1];
            $data = substr($data, strpos($data, ',') + 1);
        } else {
            $ext = 'jpg';
        }

        $decoded = base64_decode($data);
        if ($decoded === false) {
            Response::error('Imagen inválida');
        }

        $filename = $type . '_' . time() . '.' . $ext;
        $filepath = $uploadsDir . '/' . $filename;
        file_put_contents($filepath, $decoded);
    }

    // Save record
    $db->execute('INSERT INTO patient_photos (patient_id, type, filename, created_at) VALUES (?, ?, ?, NOW())', [$id, $type, $filename]);
    $photoId = $db->lastInsertId();

    if (class_exists('Audit')) {
        Audit::log('upload_photo', 'patient', $id, ['photo_id' => $photoId, 'filename' => $filename, 'type' => $type]);
    }

    Response::success(['id' => $photoId, 'filename' => $filename, 'url' => '/uploads/patients/' . $id . '/' . $filename], 'Foto subida');
}

// GET /patients - Listar pacientes
if ($method === 'GET' && !$id) {
    $query = 'SELECT * FROM patients WHERE 1=1';
    $params = [];

    // Filtrar por doctor si es necesario
    if ($user['role'] === 'doctor') {
        // Obtener staff_member_id del doctor
        $staffMember = $db->fetchOne(
            'SELECT id FROM staff_members WHERE user_id = ?',
            [$user['user_id']]
        );

        if ($staffMember) {
            $query .= ' AND id IN (SELECT DISTINCT patient_id FROM appointments WHERE staff_member_id = ?)';
            $params[] = $staffMember['id'];
        } else {
            // Doctor sin staff_member no ve nada
            Response::success(['data' => [], 'total' => 0]);
        }
    }

    // Búsqueda
    if (isset($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $query .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Filtros de rango de fechas (por fecha de creación)
    if (isset($_GET['date_from'])) {
        $query .= ' AND DATE(created_at) >= ?';
        $params[] = $_GET['date_from'];
    }

    if (isset($_GET['date_to'])) {
        $query .= ' AND DATE(created_at) <= ?';
        $params[] = $_GET['date_to'];
    }

    // Filtro por fecha de cumpleaños
    if (isset($_GET['birthday_month'])) {
        $query .= ' AND MONTH(birthday) = ?';
        $params[] = $_GET['birthday_month'];
    }

    $query .= ' ORDER BY created_at DESC LIMIT 20';
    
    $patients = $db->fetchAll($query, $params);
    Response::success(['data' => $patients, 'total' => count($patients)]);
}

// GET /patients/{id} - Ver paciente
if ($method === 'GET' && $id) {
    $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
    
    if (!$patient) {
        Response::notFound('Paciente no encontrado');
    }

    // Verificar acceso de doctor
    if ($user['role'] === 'doctor') {
        $staffMember = $db->fetchOne(
            'SELECT id FROM staff_members WHERE user_id = ?',
            [$user['user_id']]
        );

        if ($staffMember) {
            $hasAccess = $db->fetchOne(
                'SELECT id FROM appointments WHERE patient_id = ? AND staff_member_id = ? LIMIT 1',
                [$id, $staffMember['id']]
            );

            if (!$hasAccess) {
                Response::forbidden('No tienes acceso a este paciente');
            }
        } else {
            Response::forbidden('No tienes acceso a este paciente');
        }
    }

    Response::success($patient);
}

// POST /patients - Crear paciente
if ($method === 'POST' && !$id) {
    // Permitir admin, staff y doctor crear pacientes
    if (!in_array($user['role'], ['admin', 'staff', 'doctor'])) {
        Response::forbidden('No tienes permisos para crear pacientes');
    }

    // Log para debugging
    error_log('POST /patients - Input: ' . json_encode($input));
    error_log('POST /patients - User: ' . json_encode($user));
    
    $validator = Validator::make($input, [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'phone' => 'nullable|string|max:20',
        'birthday' => 'nullable|date',
        'address' => 'nullable|string|max:500',
        'nit' => 'nullable|string|max:100'
    ]);

    if (!$validator->validate()) {
        error_log('Validation failed: ' . json_encode($validator->getErrors()));
        Response::validationError($validator->getErrors());
    }

    // Verificar email único
    $existing = $db->fetchOne('SELECT id FROM patients WHERE email = ?', [$input['email']]);
    if ($existing) {
        error_log('Email already exists: ' . $input['email']);
        Response::error('El email ya está registrado', 422);
    }

    try {
        error_log('Attempting to insert patient...');
        // Ensure 'nit' column exists (best-effort)
        try {
            $db->execute("ALTER TABLE patients ADD COLUMN IF NOT EXISTS nit VARCHAR(100) NULL", []);
        } catch (Exception $e) {
            // ignore
        }

        $result = $db->execute(
            'INSERT INTO patients (name, email, phone, birthday, address, nit, loyalty_points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())',
            [
                $input['name'],
                $input['email'],
                $input['phone'] ?? null,
                $input['birthday'] ?? null,
                $input['address'] ?? null,
                $input['nit'] ?? null
            ]
        );
        
        error_log('Insert result: ' . $result);

        $patientId = $db->lastInsertId();
        error_log('Last insert ID: ' . $patientId);
        
        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$patientId]);
        error_log('Patient fetched: ' . json_encode($patient));

        // Audit log
        if (class_exists('Audit')) {
            Audit::log('create_patient', 'patient', $patientId, ['name' => $input['name'], 'email' => $input['email']]);
        }

        Response::success($patient, 'Paciente creado exitosamente', 201);
    } catch (Exception $e) {
        error_log('Create patient error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        Response::error('Error al crear paciente: ' . $e->getMessage());
    }
}

// PUT /patients/{id} - Actualizar paciente
if ($method === 'PUT' && $id) {
    $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
    
    if (!$patient) {
        Response::notFound('Paciente no encontrado');
    }

    // Permisos: sólo admin/staff/doctor o el mismo paciente pueden actualizar
    if ($user['role'] === 'patient') {
        // permitir sólo si el paciente tiene asociado el mismo user_id o el mismo email
        if (!isset($patient['user_id']) || $patient['user_id'] != $user['user_id']) {
            if (empty($patient['email']) || $patient['email'] !== $user['email']) {
                Response::forbidden('No tienes permiso para actualizar este paciente');
            }
        }
    } else if (!in_array($user['role'], ['admin', 'staff', 'doctor'])) {
        Response::forbidden('No tienes permiso para actualizar pacientes');
    }

    $validator = Validator::make($input, [
        'name' => 'string|max:255',
        'email' => 'email|max:255',
        'phone' => 'string|max:20',
        'birthday' => 'date',
        'address' => 'string|max:500',
        'nit' => 'nullable|string|max:100'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        $updates = [];
        $params = [];

        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $params[] = $input['name'];
        }
        if (isset($input['email'])) {
            $updates[] = 'email = ?';
            $params[] = $input['email'];
        }
        if (isset($input['phone'])) {
            $updates[] = 'phone = ?';
            $params[] = $input['phone'];
        }
        if (isset($input['birthday'])) {
            $updates[] = 'birthday = ?';
            $params[] = $input['birthday'];
        }
        if (isset($input['address'])) {
            $updates[] = 'address = ?';
            $params[] = $input['address'];
        }
        if (isset($input['nit'])) {
            // Ensure column (best-effort)
            try { $db->execute("ALTER TABLE patients ADD COLUMN IF NOT EXISTS nit VARCHAR(100) NULL", []); } catch (Exception $e) {}
            $updates[] = 'nit = ?';
            $params[] = $input['nit'];
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $query = 'UPDATE patients SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->execute($query, $params);

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);

        // Audit log
        if (class_exists('Audit')) {
            Audit::log('update_patient', 'patient', $id, ['updates' => $updates]);
        }

        Response::success($patient, 'Paciente actualizado exitosamente');
    } catch (Exception $e) {
        error_log('Update patient error: ' . $e->getMessage());
        Response::error('Error al actualizar paciente');
    }
}

// DELETE /patients/{id} - Eliminar paciente
if ($method === 'DELETE' && $id) {
    Auth::requireRole('admin'); // Solo admin puede eliminar

    $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
    
    if (!$patient) {
        Response::notFound('Paciente no encontrado');
    }

    try {
        $db->execute('DELETE FROM patients WHERE id = ?', [$id]);

        if (class_exists('Audit')) {
            Audit::log('delete_patient', 'patient', $id, []);
        }

        Response::success(null, 'Paciente eliminado exitosamente');
    } catch (Exception $e) {
        error_log('Delete patient error: ' . $e->getMessage());
        Response::error('Error al eliminar paciente');
    }
}

// POST /patients/{id}/loyalty/add - Añadir puntos de lealtad
if ($method === 'POST' && $id && $action === 'loyalty-add') {
    $validator = Validator::make($input, [
        'points' => 'required|integer|min:1|max:10000'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        $db->execute(
            'UPDATE patients SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE id = ?',
            [$input['points'], $id]
        );

        if (class_exists('Audit')) {
            Audit::log('add_loyalty', 'patient', $id, ['points' => $input['points']]);
        }

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        Response::success($patient, 'Puntos añadidos exitosamente');
    } catch (Exception $e) {
        error_log('Add loyalty points error: ' . $e->getMessage());
        Response::error('Error al añadir puntos');
    }
}

// POST /patients/{id}/loyalty/redeem - Canjear puntos
if ($method === 'POST' && $id && $action === 'loyalty-redeem') {
    $validator = Validator::make($input, [
        'points' => 'required|integer|min:1|max:10000'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    $patient = $db->fetchOne('SELECT loyalty_points FROM patients WHERE id = ?', [$id]);
    
    if (!$patient) {
        Response::notFound('Paciente no encontrado');
    }

    if ($patient['loyalty_points'] < $input['points']) {
        Response::error('Puntos insuficientes', 400);
    }

    try {
        $db->execute(
            'UPDATE patients SET loyalty_points = loyalty_points - ?, updated_at = NOW() WHERE id = ?',
            [$input['points'], $id]
        );

        if (class_exists('Audit')) {
            Audit::log('redeem_loyalty', 'patient', $id, ['points' => $input['points']]);
        }

        $patient = $db->fetchOne('SELECT * FROM patients WHERE id = ?', [$id]);
        Response::success($patient, 'Puntos canjeados exitosamente');
    } catch (Exception $e) {
        error_log('Redeem loyalty points error: ' . $e->getMessage());
        Response::error('Error al canjear puntos');
    }
}

Response::error('Método no permitido', 405);
