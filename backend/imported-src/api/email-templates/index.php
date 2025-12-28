<?php
/**
 * Email Templates CRUD
 * GET /email-templates - List all
 * GET /email-templates/:id - Get one
 * POST /email-templates - Create
 * PUT /email-templates/:id - Update
 * DELETE /email-templates/:id - Delete
 */

Auth::requireAuth();
$user = Auth::getCurrentUser();

// Only superadmin and admin can manage templates
if (!in_array($user['role'], ['superadmin', 'admin'])) {
    Response::forbidden('No tienes permisos para gestionar templates de email');
}

$db = Database::getInstance();

// GET all templates
if ($method === 'GET' && !$id) {
    $templates = $db->fetchAll('SELECT * FROM email_templates ORDER BY name ASC');
    Response::success(['templates' => $templates]);
}

// GET one template
if ($method === 'GET' && $id) {
    $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
    if (!$template) {
        Response::notFound('Template no encontrado');
    }
    Response::success(['template' => $template]);
}

// POST create template
if ($method === 'POST') {
    $validator = Validator::make($input, [
        'name' => 'required|string|max:100',
        'subject' => 'required|string|max:255',
        'body' => 'required|string',
        'variables' => 'string'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        $templateId = $db->execute(
            'INSERT INTO email_templates (name, subject, body, variables, is_html, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $input['name'],
                $input['subject'],
                $input['body'],
                $input['variables'] ?? '[]',
                isset($input['is_html']) ? (int)$input['is_html'] : 1
            ]
        );
        $templateId = $db->lastInsertId();

        if (class_exists('Audit')) {
            Audit::log('create_email_template', 'email_template', $templateId, ['name' => $input['name']]);
        }

        $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$templateId]);
        Response::success(['template' => $template], 'Template creado exitosamente', 201);
    } catch (Exception $e) {
        error_log('Create email_template error: ' . $e->getMessage());
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
            Response::error('Ya existe un template con ese nombre', 422);
        }
        Response::error('Error al crear template');
    }
}

// PUT update template
if ($method === 'PUT' && $id) {
    $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
    if (!$template) {
        Response::notFound('Template no encontrado');
    }

    $validator = Validator::make($input, [
        'name' => 'string|max:100',
        'subject' => 'string|max:255',
        'body' => 'string',
        'variables' => 'string'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    $updates = [];
    $params = [];
    
    $allowed = ['name', 'subject', 'body', 'variables', 'is_html'];
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $updates[] = "{$field} = ?";
            $params[] = $field === 'is_html' ? (int)$input[$field] : $input[$field];
        }
    }

    if (empty($updates)) {
        Response::error('No hay datos para actualizar', 400);
    }

    $updates[] = 'updated_at = NOW()';
    $params[] = $id;

    try {
        $db->execute(
            'UPDATE email_templates SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );

        if (class_exists('Audit')) {
            Audit::log('update_email_template', 'email_template', $id, ['updates' => $updates]);
        }

        $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
        Response::success(['template' => $template], 'Template actualizado');
    } catch (Exception $e) {
        error_log('Update email_template error: ' . $e->getMessage());
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
            Response::error('Ya existe un template con ese nombre', 422);
        }
        Response::error('Error al actualizar template');
    }
}

// DELETE template
if ($method === 'DELETE' && $id) {
    $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
    if (!$template) {
        Response::notFound('Template no encontrado');
    }

    try {
        $db->execute('DELETE FROM email_templates WHERE id = ?', [$id]);

        if (class_exists('Audit')) {
            Audit::log('delete_email_template', 'email_template', $id, ['name' => $template['name']]);
        }

        Response::success(null, 'Template eliminado');
    } catch (Exception $e) {
        error_log('Delete email_template error: ' . $e->getMessage());
        Response::error('Error al eliminar template');
    }
}

Response::error('Método no permitido', 405);
