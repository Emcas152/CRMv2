<?php
/**
 * Endpoints de Ventas (Sales)
 */

Auth::requireAuth();

$user = Auth::getCurrentUser();
$db = Database::getInstance();

// GET /sales - Listar ventas
if ($method === 'GET' && !$id) {
    $query = 'SELECT s.id, s.patient_id, s.subtotal, s.discount, s.total, 
              s.payment_method, s.status, s.notes, s.created_at, s.updated_at,
              p.name as patient_name, p.email as patient_email
              FROM sales s
              LEFT JOIN patients p ON s.patient_id = p.id
              WHERE 1=1';
    $params = [];

    // Filtros de rango de fechas
    if (isset($_GET['date_from'])) {
        $query .= ' AND DATE(s.created_at) >= ?';
        $params[] = $_GET['date_from'];
    }

    if (isset($_GET['date_to'])) {
        $query .= ' AND DATE(s.created_at) <= ?';
        $params[] = $_GET['date_to'];
    }

    // Filtro por paciente
    if (isset($_GET['patient_id'])) {
        $query .= ' AND s.patient_id = ?';
        $params[] = $_GET['patient_id'];
    }

    // Filtro por estado
    if (isset($_GET['status'])) {
        $query .= ' AND s.status = ?';
        $params[] = $_GET['status'];
    }

    // Filtro por método de pago
    if (isset($_GET['payment_method'])) {
        $query .= ' AND s.payment_method = ?';
        $params[] = $_GET['payment_method'];
    }

    $query .= ' ORDER BY s.created_at DESC';
    
    $sales = $db->fetchAll($query, $params);
    
    // Cargar items de cada venta
    foreach ($sales as &$sale) {
        $sale['items'] = $db->fetchAll(
            'SELECT si.*, p.name as product_name, p.sku
             FROM sale_items si
             LEFT JOIN products p ON si.product_id = p.id
             WHERE si.sale_id = ?',
            [$sale['id']]
        );
    }
    
    Response::success(['data' => $sales, 'total' => count($sales)]);
}

// GET /sales/{id} - Ver venta específica
if ($method === 'GET' && $id) {
    $sale = $db->fetchOne(
        'SELECT s.*, p.name as patient_name, p.email as patient_email
         FROM sales s
         LEFT JOIN patients p ON s.patient_id = p.id
         WHERE s.id = ?',
        [$id]
    );
    
    if (!$sale) {
        Response::notFound('Venta no encontrada');
    }

    // Cargar items de la venta
    $sale['items'] = $db->fetchAll(
        'SELECT si.*, p.name as product_name, p.sku
         FROM sale_items si
         LEFT JOIN products p ON si.product_id = p.id
         WHERE si.sale_id = ?',
        [$id]
    );

    Response::success($sale);
}

// POST /sales - Crear venta
if ($method === 'POST' && !$id) {
    $validator = Validator::make($input, [
        'patient_id' => 'required|integer',
        'items' => 'required|array',
        'payment_method' => 'required|in:cash,card,transfer,other',
        'discount' => 'nullable|numeric',
        'notes' => 'nullable|string|max:1000'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        // Iniciar transacción
        $db->beginTransaction();

        // Calcular totales y validar stock
        $subtotal = 0;
        foreach ($input['items'] as $item) {
            $subtotal += $item['price'] * $item['quantity'];

            // Comprobar stock si es producto
            $product = $db->fetchOne('SELECT id, name, type, stock FROM products WHERE id = ?', [$item['product_id']]);
            if (!$product) {
                throw new Exception('Producto no encontrado: ' . ($item['product_id'] ?? '')); 
            }
            if ($product['type'] === 'product') {
                $available = intval($product['stock']);
                if ($available < intval($item['quantity'])) {
                    throw new Exception('Stock insuficiente para producto: ' . $product['name']);
                }
            }
        }

        $discount = $input['discount'] ?? 0;
        $total = $subtotal - $discount;

        // Crear venta
        $db->execute(
            'INSERT INTO sales (patient_id, subtotal, discount, total, payment_method, status, notes, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $input['patient_id'],
                $subtotal,
                $discount,
                $total,
                $input['payment_method'],
                'completed',
                $input['notes'] ?? null
            ]
        );

        $saleId = $db->lastInsertId();

        // Crear items y actualizar stock
        foreach ($input['items'] as $item) {
            $itemSubtotal = $item['price'] * $item['quantity'];

            $db->execute(
                'INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $saleId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['price'],
                    $itemSubtotal
                ]
            );

            $product = $db->fetchOne('SELECT type FROM products WHERE id = ?', [$item['product_id']]);
            if ($product && $product['type'] === 'product') {
                $db->execute(
                    'UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?',
                    [$item['quantity'], $item['product_id']]
                );
            }
        }

        $db->commit();

        // Obtener la venta completa
        $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$saleId]);
        $sale['items'] = $db->fetchAll('SELECT * FROM sale_items WHERE sale_id = ?', [$saleId]);

        if (class_exists('Audit')) {
            Audit::log('create_sale', 'sale', $saleId, ['patient_id' => $input['patient_id'], 'total' => $total]);
        }

        Response::success($sale, 'Venta creada exitosamente', 201);
    } catch (Exception $e) {
        // Revertir si hubo transacción abierta
        try { $db->rollback(); } catch (Exception $_) {}
        error_log('Create sale error: ' . $e->getMessage());
        Response::error('Error al crear venta: ' . $e->getMessage());
    }
}

// PUT /sales/{id} - Actualizar venta
if ($method === 'PUT' && $id) {
    $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$id]);
    
    if (!$sale) {
        Response::notFound('Venta no encontrada');
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

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $query = 'UPDATE sales SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->execute($query, $params);

        if (class_exists('Audit')) {
            Audit::log('update_sale', 'sale', $id, ['updates' => $updates]);
        }

        $sale = $db->fetchOne('SELECT * FROM sales WHERE id = ?', [$id]);
        Response::success($sale, 'Venta actualizada exitosamente');
    } catch (Exception $e) {
        error_log('Update sale error: ' . $e->getMessage());
        Response::error('Error al actualizar venta');
    }
}

// DELETE /sales/{id} - Eliminar venta
if ($method === 'DELETE' && $id) {
    try {
        // Restaurar stock de productos antes de eliminar
        $items = $db->fetchAll('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?', [$id]);
        foreach ($items as $item) {
            $product = $db->fetchOne('SELECT type FROM products WHERE id = ?', [$item['product_id']]);
            if ($product && $product['type'] === 'product') {
                $db->execute(
                    'UPDATE products SET stock = stock + ?, updated_at = NOW() WHERE id = ?',
                    [$item['quantity'], $item['product_id']]
                );
            }
        }

        // Eliminar venta (los items se eliminan en cascada)
        $db->execute('DELETE FROM sales WHERE id = ?', [$id]);

        if (class_exists('Audit')) {
            Audit::log('delete_sale', 'sale', $id, []);
        }

        Response::success(null, 'Venta eliminada exitosamente');
    } catch (Exception $e) {
        error_log('Delete sale error: ' . $e->getMessage());
        Response::error('Error al eliminar venta');
    }
}

Response::error('Método no permitido', 405);
