<?php
namespace App\Controllers;

use App\Core\Request;

class StaffMembersController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
    }

    public function handle($id = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        try {
            $authUser = \App\Core\Auth::getCurrentUser();
            if (!$authUser || !is_array($authUser)) {
                \App\Core\Response::unauthorized('Token inválido o expirado');
            }

            // Needed for appointments assignment / filtering.
            if (!in_array($authUser['role'], ['superadmin', 'admin', 'staff'], true)) {
                \App\Core\Response::forbidden('No tienes permisos para ver staff');
            }

            if ($method === 'GET' && !$id) {
                return $this->index();
            }

            \App\Core\Response::error('Método no permitido', 405);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::handle($e);
        }
    }

    private function index()
    {
        $db = \App\Core\Database::getInstance();

        try {
            $rows = $db->fetchAll('SELECT id, name FROM staff_members ORDER BY name ASC');
            \App\Core\Response::success(['data' => $rows, 'total' => count($rows)]);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al listar staff', $e);
        }
    }
}
