<?php
/**
 * Endpoints de Productos
 */

Auth::requireAuth();

$user = Auth::getCurrentUser();
$db = Database::getInstance();

// GET /products - Listar productos
if ($method === 'GET' && !$id) {
    $query = 'SELECT * FROM products WHERE 1=1';
    $params = [];

    // Filtros
    if (isset($_GET['type'])) {
        $query .= ' AND type = ?';
        $params[] = $_GET['type'];
    }

    if (isset($_GET['active'])) {
        $query .= ' AND active = ?';
        $params[] = $_GET['active'] ? 1 : 0;
    }

    if (isset($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $query .= ' AND (name LIKE ? OR sku LIKE ? OR description LIKE ?)';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $query .= ' ORDER BY created_at DESC LIMIT 20';
    
    $products = $db->fetchAll($query, $params);
    Response::success(['data' => $products, 'total' => count($products)]);
}

// GET /products/{id} - Ver producto
if ($method === 'GET' && $id) {
    $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    
    if (!$product) {
        Response::notFound('Producto no encontrado');
    }

    Response::success($product);
}

// POST /products - Crear producto
if ($method === 'POST' && !$id) {
    // Solo admin y staff
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden();
    }

    $validator = Validator::make($input, [
        'name' => 'required|string|max:255',
        'sku' => 'string|max:100',
        'description' => 'string|max:2000',
        'price' => 'required|numeric|min:0|max:999999.99',
        'stock' => 'integer|min:0|max:999999',
        'type' => 'required|in:product,service',
        'active' => 'boolean'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    try {
        $db->execute(
            'INSERT INTO products (name, sku, description, price, stock, type, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $input['name'],
                $input['sku'] ?? null,
                $input['description'] ?? null,
                $input['price'],
                $input['stock'] ?? 0,
                $input['type'],
                isset($input['active']) ? ($input['active'] ? 1 : 0) : 1
            ]
        );

        $productId = $db->lastInsertId();
        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$productId]);

        if (class_exists('Audit')) {
            Audit::log('create_product', 'product', $productId, ['name' => $input['name'], 'sku' => $input['sku'] ?? null]);
        }

        Response::success($product, 'Producto creado exitosamente', 201);
    } catch (Exception $e) {
        error_log('Create product error: ' . $e->getMessage());
        Response::error('Error al crear producto');
    }
}

// POST /products/{id}/upload-image - Subir imagen de producto
if ($id && $action === 'upload-image' && $method === 'POST') {
    // Solo admin y staff
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden('No tienes permisos para subir imagenes');
    }

    if (empty($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        Response::error('No se recibió imagen', 400);
    }

    $file = $_FILES['image'];
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        Response::error('Tipo de archivo no permitido', 422);
    }

    // Crear carpeta
    $uploadsDir = __DIR__ . '/../../uploads/products/' . $id;
    @mkdir($uploadsDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'product_' . $id . '_' . time() . '.' . $ext;
    $dest = $uploadsDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        Response::error('Error al mover el archivo', 500);
    }

    // Intentar crear columna image_url si no existe (silencioso en caso de error)
    try {
        $db->execute("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL AFTER description", []);
    } catch (Exception $e) {
        // algunos MySQL no soportan IF NOT EXISTS en ALTER TABLE columnas; ignorar error
    }

    $imageUrl = '/uploads/products/' . $id . '/' . $filename;
    try {
        $db->execute('UPDATE products SET image_url = ?, updated_at = NOW() WHERE id = ?', [$imageUrl, $id]);
    } catch (Exception $e) {
        error_log('Update product image error: ' . $e->getMessage());
    }

    if (class_exists('Audit')) {
        Audit::log('upload_product_image', 'product', $id, ['filename' => $filename]);
    }

    Response::success(['image_url' => $imageUrl], 'Imagen subida');
}

// PUT /products/{id} - Actualizar producto
if ($method === 'PUT' && $id) {
    // Solo admin y staff
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden();
    }

    $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    
    if (!$product) {
        Response::notFound('Producto no encontrado');
    }

    try {
        $updates = [];
        $params = [];

        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $params[] = $input['name'];
        }
        if (isset($input['sku'])) {
            $updates[] = 'sku = ?';
            $params[] = $input['sku'];
        }
        if (isset($input['description'])) {
            $updates[] = 'description = ?';
            $params[] = $input['description'];
        }
        if (isset($input['price'])) {
            $updates[] = 'price = ?';
            $params[] = $input['price'];
        }
        if (isset($input['stock'])) {
            $updates[] = 'stock = ?';
            $params[] = $input['stock'];
        }
        if (isset($input['type'])) {
            $updates[] = 'type = ?';
            $params[] = $input['type'];
        }
        if (isset($input['active'])) {
            $updates[] = 'active = ?';
            $params[] = $input['active'] ? 1 : 0;
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $id;

        $query = 'UPDATE products SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $db->execute($query, $params);

        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);

        if (class_exists('Audit')) {
            Audit::log('update_product', 'product', $id, ['updates' => $updates]);
        }

        Response::success($product, 'Producto actualizado exitosamente');
    } catch (Exception $e) {
        error_log('Update product error: ' . $e->getMessage());
        Response::error('Error al actualizar producto');
    }
}

// DELETE /products/{id} - Eliminar producto
if ($method === 'DELETE' && $id) {
    // Permitir admin y staff eliminar productos (coincide con permisos de crear/editar)
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden('No tienes permisos para eliminar productos');
    }

    try {
        $db->execute('DELETE FROM products WHERE id = ?', [$id]);

        if (class_exists('Audit')) {
            Audit::log('delete_product', 'product', $id, []);
        }

        Response::success(null, 'Producto eliminado exitosamente');
    } catch (Exception $e) {
        error_log('Delete product error: ' . $e->getMessage());
        Response::error('Error al eliminar producto');
    }
}

// POST /products/{id}/adjust-stock - Ajustar inventario
if ($method === 'POST' && $id && $action === 'adjust-stock') {
    // Solo admin y staff
    if (!in_array($user['role'], ['admin', 'staff'])) {
        Response::forbidden();
    }

    $validator = Validator::make($input, [
        'quantity' => 'required|integer',
        'type' => 'required|in:add,subtract,set'
    ]);

    if (!$validator->validate()) {
        Response::validationError($validator->getErrors());
    }

    $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    
    if (!$product) {
        Response::notFound('Producto no encontrado');
    }

    if ($product['type'] === 'service') {
        Response::error('Los servicios no tienen inventario', 400);
    }

    try {
        $newStock = $product['stock'];

        switch ($input['type']) {
            case 'add':
                $newStock += abs($input['quantity']);
                break;
            case 'subtract':
                $newStock -= abs($input['quantity']);
                if ($newStock < 0) {
                    Response::error('Stock insuficiente', 400);
                }
                break;
            case 'set':
                $newStock = abs($input['quantity']);
                break;
        }

        if ($newStock > 999999) {
            Response::error('Stock excede límite máximo', 400);
        }

        $db->execute(
            'UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?',
            [$newStock, $id]
        );

        if (class_exists('Audit')) {
            Audit::log('adjust_stock', 'product', $id, ['new_stock' => $newStock, 'type' => $input['type'], 'quantity' => $input['quantity']]);
        }

        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
        Response::success($product, 'Stock actualizado exitosamente');
    } catch (Exception $e) {
        error_log('Adjust stock error: ' . $e->getMessage());
        Response::error('Error al ajustar stock');
    }
}

Response::error('Método no permitido', 405);
