<?php
namespace App\Controllers;

use App\Core\Request;

class SalesController
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

        \App\Core\Auth::requireAuth();
        $user = \App\Core\Auth::getCurrentUser();
        $role = (string)($user['role'] ?? '');

        // Patients do not have access to sales per role rules.
        if ($role === 'patient') {
            \App\Core\Response::forbidden('No tienes permisos para acceder a ventas');
        }

        // Staff/doctor/admin/superadmin: full module access
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'doctor', 'staff'], 'No tienes permisos para acceder a ventas');

        try {
            if ($method === 'GET' && !$id) {
                return $this->index($user);
            }

            if ($method === 'GET' && $id) {
                return $this->show($id, $user);
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

    private function getPatientIdForUser($user, $db): ?int
    {
        $userId = intval($user['user_id'] ?? 0);
        $email = (string)($user['email'] ?? '');
        $row = $db->fetchOne('SELECT id FROM patients WHERE user_id = ? OR (email != "" AND email = ?) LIMIT 1', [$userId, $email]);
        return $row ? intval($row['id']) : null;
    }

    private function index($user)
    {
        $db = \App\Core\Database::getInstance();
        $role = (string)($user['role'] ?? '');

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min(200, $perPage);
        $offset = ($page - 1) * $perPage;

        $query = 'SELECT s.id, s.patient_id, s.subtotal, s.discount, s.total,
              s.loyalty_points_awarded, s.created_by,
              s.payment_method, s.status, s.notes, s.created_at, s.updated_at,
              p.name as patient_name, p.email as patient_email
              FROM sales s
              LEFT JOIN patients p ON s.patient_id = p.id
              WHERE 1=1';
        $params = [];

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId) {
                \App\Core\Response::success(['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage]);
            }
            $query .= ' AND s.patient_id = ?';
            $params[] = $patientId;
        }

        if (isset($_GET['date_from'])) {
            $query .= ' AND DATE(s.created_at) >= ?';
            $params[] = $_GET['date_from'];
        }

        if (isset($_GET['date_to'])) {
            $query .= ' AND DATE(s.created_at) <= ?';
            $params[] = $_GET['date_to'];
        }

        if (isset($_GET['patient_id'])) {
            $query .= ' AND s.patient_id = ?';
            $params[] = $_GET['patient_id'];
        }

        if (isset($_GET['status'])) {
            $query .= ' AND s.status = ?';
            $params[] = $_GET['status'];
        }

        if (isset($_GET['payment_method'])) {
            $query .= ' AND s.payment_method = ?';
            $params[] = $_GET['payment_method'];
        }

        // Total count (for pagination)
        $countQuery = 'SELECT COUNT(*) as total FROM sales s WHERE 1=1';
        $countParams = [];

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId) {
                $countQuery .= ' AND 1=0';
            } else {
                $countQuery .= ' AND s.patient_id = ?';
                $countParams[] = $patientId;
            }
        }
        if (isset($_GET['date_from'])) {
            $countQuery .= ' AND DATE(s.created_at) >= ?';
            $countParams[] = $_GET['date_from'];
        }
        if (isset($_GET['date_to'])) {
            $countQuery .= ' AND DATE(s.created_at) <= ?';
            $countParams[] = $_GET['date_to'];
        }
        if (isset($_GET['patient_id'])) {
            $countQuery .= ' AND s.patient_id = ?';
            $countParams[] = $_GET['patient_id'];
        }
        if (isset($_GET['status'])) {
            $countQuery .= ' AND s.status = ?';
            $countParams[] = $_GET['status'];
        }
        if (isset($_GET['payment_method'])) {
            $countQuery .= ' AND s.payment_method = ?';
            $countParams[] = $_GET['payment_method'];
        }
        $countRow = $db->fetchOne($countQuery, $countParams);
        $totalCount = intval($countRow['total'] ?? 0);

        $query .= ' ORDER BY s.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        $sales = $db->fetchAll($query, $params);

        foreach ($sales as &$sale) {
            $sale['items'] = $db->fetchAll(
                'SELECT si.*, p.name as product_name, p.sku
             FROM sale_items si
             LEFT JOIN products p ON si.product_id = p.id
             WHERE si.sale_id = ?',
                [$sale['id']]
            );
        }

        \App\Core\Response::success(['data' => $sales, 'total' => $totalCount, 'page' => $page, 'per_page' => $perPage]);
    }

    private function show($id, $user)
    {
        $db = \App\Core\Database::getInstance();
        $role = (string)($user['role'] ?? '');

        $sale = $db->fetchOne(
            'SELECT s.*, p.name as patient_name, p.email as patient_email
         FROM sales s
         LEFT JOIN patients p ON s.patient_id = p.id
         WHERE s.id = ?',
            [$id]
        );

        if (!$sale) {
            \App\Core\Response::notFound('Venta no encontrada');
        }

        if ($role === 'patient') {
            $patientId = $this->getPatientIdForUser($user, $db);
            if (!$patientId || intval($sale['patient_id'] ?? 0) !== $patientId) {
                \App\Core\Response::forbidden('No tienes acceso a esta compra');
            }
        }

        $sale['items'] = $db->fetchAll(
            'SELECT si.*, p.name as product_name, p.sku
         FROM sale_items si
         LEFT JOIN products p ON si.product_id = p.id
         WHERE si.sale_id = ?',
            [$id]
        );

        \App\Core\Response::success($sale);
    }

    private function store($input)
    {
        $validator = \App\Core\Validator::make($input, [
            'patient_id' => 'required|integer',
            'items' => 'required|array',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'discount' => 'numeric',
            'notes' => 'string|max:1000',
            'loyalty_points' => 'integer|min:0|max:1000000',
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
            \App\Core\Response::validationError(['items' => 'Se requieren items']);
        }

        $db = \App\Core\Database::getInstance();
        $user = \App\Core\Auth::getCurrentUser();
        $createdBy = $user && isset($user['user_id']) ? intval($user['user_id']) : null;

        try {
            $db->beginTransaction();

            $patientId = intval($input['patient_id']);
            $patient = $db->fetchOne('SELECT id, loyalty_points FROM patients WHERE id = ?', [$patientId]);
            if (!$patient) {
                throw new \Exception('Paciente no encontrado');
            }

            $subtotal = 0;
            foreach ($input['items'] as $item) {
                $productId = $item['product_id'] ?? null;
                $price = $item['price'] ?? null;
                $quantity = $item['quantity'] ?? null;

                if (!$productId || $price === null || $quantity === null) {
                    throw new \Exception('Item inválido: se requiere product_id, price, quantity');
                }

                $subtotal += floatval($price) * intval($quantity);

                $product = $db->fetchOne('SELECT id, name, type, stock FROM products WHERE id = ?', [$productId]);
                if (!$product) {
                    throw new \Exception('Producto no encontrado: ' . $productId);
                }
                if (($product['type'] ?? null) === 'product') {
                    $available = intval($product['stock']);
                    if ($available < intval($quantity)) {
                        throw new \Exception('Stock insuficiente para producto: ' . ($product['name'] ?? '')); 
                    }
                }
            }

            $discount = floatval($input['discount'] ?? 0);
            $total = $subtotal - $discount;
            if ($total < 0) {
                throw new \Exception('El descuento no puede ser mayor al subtotal');
            }

            $points = intval($input['loyalty_points'] ?? 0);

            $db->execute(
                'INSERT INTO sales (patient_id, created_by, subtotal, discount, total, loyalty_points_awarded, payment_method, status, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $patientId,
                    $createdBy,
                    $subtotal,
                    $discount,
                    $total,
                    $points,
                    $input['payment_method'],
                    'completed',
                    $input['notes'] ?? null,
                ]
            );

            $saleId = $db->lastInsertId();

            foreach ($input['items'] as $item) {
                $itemSubtotal = floatval($item['price']) * intval($item['quantity']);

                $db->execute(
                    'INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $saleId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['price'],
                        $itemSubtotal,
                    ]
                );

                $product = $db->fetchOne('SELECT type FROM products WHERE id = ?', [$item['product_id']]);
                if ($product && ($product['type'] ?? null) === 'product') {
                    $db->execute(
                        'UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?',
                        [$item['quantity'], $item['product_id']]
                    );
                }
            }

            // Loyalty points (awarded on completed sale)
            if ($points > 0) {
                $db->execute('UPDATE patients SET loyalty_points = loyalty_points + ?, updated_at = NOW() WHERE id = ?', [$points, $patientId]);
            }

            $db->commit();

            $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$saleId]);
            $sale['items'] = $db->fetchAll('SELECT * FROM sale_items WHERE sale_id = ?', [$saleId]);
            if ($points > 0) {
                $sale['patient_loyalty_points'] = intval(($db->fetchOne('SELECT loyalty_points FROM patients WHERE id = ?', [$patientId]))['loyalty_points'] ?? 0);
            }

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_sale', 'sale', $saleId, ['patient_id' => $input['patient_id'], 'total' => $total]);
            }

            \App\Core\Response::success($sale, 'Venta creada exitosamente', 201);
        } catch (\Exception $e) {
            try {
                $db->rollback();
            } catch (\Exception $_) {
            }
            if ($e instanceof \PDOException) {
                \App\Core\Response::dbException('Error al crear venta', $e);
            }
            // Errores de negocio/validación lanzados manualmente arriba
            \App\Core\Response::exception((string)$e->getMessage(), $e, 422);
        }
    }

    private function update($id, $input)
    {
        $db = \App\Core\Database::getInstance();

        $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$id]);
        if (!$sale) {
            \App\Core\Response::notFound('Venta no encontrada');
        }

        try {
            $updates = [];
            $params = [];

            if (isset($input['status'])) {
                $updates[] = 'status = ?';
                $params[] = $input['status'];
            }
            if (isset($input['payment_method'])) {
                $updates[] = 'payment_method = ?';
                $params[] = $input['payment_method'];
            }
            if (isset($input['notes'])) {
                $updates[] = 'notes = ?';
                $params[] = $input['notes'];
            }

            if (empty($updates)) {
                \App\Core\Response::success($sale, 'Sin cambios');
            }

            $updates[] = 'updated_at = NOW()';
            $params[] = $id;

            $query = 'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $db->execute($query, $params);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_sale', 'sale', $id, ['updates' => $updates]);
            }

            $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$id]);
            \App\Core\Response::success($sale, 'Venta actualizada exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar venta', $e);
        }
    }

    private function destroy($id)
    {
        $db = \App\Core\Database::getInstance();

        try {
            $items = $db->fetchAll('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?', [$id]);
            foreach ($items as $item) {
                $product = $db->fetchOne('SELECT type FROM products WHERE id = ?', [$item['product_id']]);
                if ($product && ($product['type'] ?? null) === 'product') {
                    $db->execute(
                        'UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?',
                        [$item['quantity'], $item['product_id']]
                    );
                }
            }

            $db->execute('DELETE FROM sales WHERE id = ?', [$id]);

            if (class_exists('\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_sale', 'sale', $id, []);
            }

            \App\Core\Response::success(null, 'Venta eliminada exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar venta', $e);
        }
    }
}


