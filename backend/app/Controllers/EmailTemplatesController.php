<?php
namespace App\Controllers;

use App\Core\Request;

class EmailTemplatesController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        try {
            \App\Core\Auth::requireAuth();
            $user = \App\Core\Auth::getCurrentUser();

            if (!in_array($user['role'], ['superadmin', 'admin'])) {
                \App\Core\Response::forbidden('No tienes permisos para gestionar templates de email');
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

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();
        $templates = $db->fetchAll('SELECT * FROM email_templates ORDER BY name ASC');
        \App\Core\Response::success(['templates' => $templates]);
    }

    private function show($id)
    {
        $db = \App\Core\Database::getInstance();
        $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
        if (!$template) {
            \App\Core\Response::notFound('Template no encontrado');
        }
        \App\Core\Response::success(['template' => $template]);
    }

    private function store($input)
    {
        $validator = \App\Core\Validator::make($input, [
            'name' => 'required|string|max:100',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'variables' => 'string',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();

        try {
            $db->execute(
                'INSERT INTO email_templates (name, subject, body, variables, is_html, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $input['name'],
                    $input['subject'],
                    $input['body'],
                    $input['variables'] ?? '[]',
                    isset($input['is_html']) ? (int) $input['is_html'] : 1,
                ]
            );

            $templateId = $db->lastInsertId();

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_email_template', 'email_template', $templateId, ['name' => $input['name']]);
            }

            $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$templateId]);
            \App\Core\Response::success(['template' => $template], 'Template creado exitosamente', 201);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
                \App\Core\Response::error('Ya existe un template con ese nombre', 422);
            }
            \App\Core\Response::dbException('Error al crear template', $e);
        }
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();
        $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
        if (!$template) {
            \App\Core\Response::notFound('Template no encontrado');
        }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'string|max:100',
            'subject' => 'string|max:255',
            'body' => 'string',
            'variables' => 'string',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $updates = [];
        $params = [];
        foreach (['name', 'subject', 'body', 'variables', 'is_html'] as $field) {
            if (isset($input[$field])) {
                $updates[] = $field . ' = ?';
                $params[] = ($field === 'is_html') ? (int) $input[$field] : $input[$field];
            }
        }

        if (empty($updates)) {
            \App\Core\Response::error('No hay datos para actualizar', 400);
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        try {
            $db->execute('UPDATE email_templates SET ' . implode(', ', $updates) . ' WHERE id = ?', $params);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_email_template', 'email_template', $id, ['updates' => $updates]);
            }

            $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
            \App\Core\Response::success(['template' => $template], 'Template actualizado');
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
                \App\Core\Response::error('Ya existe un template con ese nombre', 422);
            }
            \App\Core\Response::dbException('Error al actualizar template', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();
        $template = $db->fetchOne('SELECT * FROM email_templates WHERE id = ?', [$id]);
        if (!$template) {
            \App\Core\Response::notFound('Template no encontrado');
        }

        try {
            $db->execute('DELETE FROM email_templates WHERE id = ?', [$id]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_email_template', 'email_template', $id, ['name' => $template['name'] ?? null]);
            }

            \App\Core\Response::success(null, 'Template eliminado');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar template', $e);
        }
    }
}
