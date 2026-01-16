<?php
namespace App\Controllers;

use App\Core\Request;

class ProductsController
{
    protected static function initCore()
    {
        require_once __DIR__ . '/../Core/helpers.php';
        require_once __DIR__ . '/../Core/Request.php';
        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/Auth.php';
        require_once __DIR__ . '/../Core/Validator.php';
        require_once __DIR__ . '/../Core/Sanitizer.php';
        require_once __DIR__ . '/../Core/Response.php';
        require_once __DIR__ . '/../Core/Audit.php';
        require_once __DIR__ . '/../Core/ErrorHandler.php';
        require_once __DIR__ . '/../Core/FieldEncryption.php';
    }

    public function handle($id = null, $action = null)
    {
        self::initCore();

        $method = $_SERVER['REQUEST_METHOD'];
        $input = Request::body();

        \App\Core\Auth::requireAuth();

        if ($id && $action === 'upload-image' && $method === 'POST') {
            return $this->uploadImage($id);
        }

        if ($id && $action === 'adjust-stock' && $method === 'POST') {
            return $this->adjustStock($id, $input);
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
    }

    private function index()
    {
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'staff'], 'No tienes permisos para ver productos');
        $db = \App\Core\Database::getInstance();

        $query = 'SELECT * FROM products WHERE 1=1';
        $params = [];

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
        \App\Core\Response::success(['data' => $products, 'total' => count($products)]);
    }

    private function show($id)
    {
        \App\Core\Auth::requireAnyRole(['superadmin', 'admin', 'staff'], 'No tienes permisos para ver productos');
        $db = \App\Core\Database::getInstance();
        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);

        if (!$product) {
            \App\Core\Response::notFound('Producto no encontrado');
        }

        // Desencriptar precio si existe
        if (!empty($product['price_encrypted'])) {
            try {
                $product['price'] = \App\Core\FieldEncryption::decryptValue($product['price_encrypted']);
            } catch (\Exception $e) {
                error_log("Error desencriptando precio del producto {$id}: " . $e->getMessage());
            }
        }

        \App\Core\Response::success($product);
    }

    private function store($input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'staff'])) {
            \App\Core\Response::forbidden();
        }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'required|string|max:255',
            'sku' => 'string|max:100',
            'description' => 'string|max:2000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'stock' => 'integer|min:0|max:999999',
            'type' => 'required|in:product,service',
            'active' => 'boolean'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();

        try {
            // Validar precio antes de encriptar
            if (!empty($input['price'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['price'], \App\Core\FieldEncryption::TYPE_PRICE)) {
                    \App\Core\Response::validationError(['price' => 'Precio inválido']);
                }
            }

            $db->execute(
                'INSERT INTO products (name, sku, description, price, price_encrypted, stock, type, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $input['name'],
                    $input['sku'] ?? null,
                    $input['description'] ?? null,
                    $input['price'],
                    \App\Core\FieldEncryption::encryptValue($input['price']),
                    $input['stock'] ?? 0,
                    $input['type'],
                    isset($input['active']) ? ($input['active'] ? 1 : 0) : 1
                ]
            );

            $productId = $db->lastInsertId();
            $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$productId]);

            if (class_exists('\\\App\\Core\\Audit')) {
                \App\Core\Audit::log('create_product', 'product', $productId, ['name' => $input['name'], 'sku' => $input['sku'] ?? null]);
            }

            \App\Core\Response::success($product, 'Producto creado exitosamente', 201);
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al crear producto', $e);
        }
    }

    private function update($id, $input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'staff'])) {
            \App\Core\Response::forbidden();
        }

        $db = \App\Core\Database::getInstance();
        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$product) {
            \App\Core\Response::notFound('Producto no encontrado');
        }

        $validator = \App\Core\Validator::make($input, [
            'name' => 'string|max:255',
            'sku' => 'string|max:100',
            'description' => 'string|max:2000',
            'price' => 'numeric|min:0|max:999999.99',
            'stock' => 'integer|min:0|max:999999',
            'type' => 'in:product,service',
            'active' => 'boolean'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        try {
            $updates = [];
            $params = [];

            foreach (['name', 'sku', 'description', 'stock', 'type'] as $field) {
                if (isset($input[$field])) {
                    $updates[] = $field . ' = ?';
                    $params[] = $input[$field];
                }
            }

            // Manejar precio con encriptación
            if (isset($input['price'])) {
                if (!\App\Core\FieldEncryption::validateValue($input['price'], \App\Core\FieldEncryption::TYPE_PRICE)) {
                    \App\Core\Response::validationError(['price' => 'Precio inválido']);
                }
                $updates[] = 'price = ?';
                $updates[] = 'price_encrypted = ?';
                $params[] = $input['price'];
                $params[] = \App\Core\FieldEncryption::encryptValue($input['price']);
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

            // Desencriptar precio para respuesta
            if (!empty($product['price_encrypted'])) {
                try {
                    $product['price'] = \App\Core\FieldEncryption::decryptValue($product['price_encrypted']);
                } catch (\Exception $e) {
                    error_log("Error desencriptando precio: " . $e->getMessage());
                }
            }

            if (class_exists('\\\App\\Core\\Audit')) {
                \App\Core\Audit::log('update_product', 'product', $id, ['updates' => $updates]);
            }

            \App\Core\Response::success($product, 'Producto actualizado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al actualizar producto', $e);
        }
    }

    private function destroy($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'staff'])) {
            \App\Core\Response::forbidden('No tienes permisos para eliminar productos');
        }

        $db = \App\Core\Database::getInstance();

        try {
            $db->execute('DELETE FROM products WHERE id = ?', [$id]);

            if (class_exists('\\\App\\Core\\Audit')) {
                \App\Core\Audit::log('delete_product', 'product', $id, []);
            }

            \App\Core\Response::success(null, 'Producto eliminado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al eliminar producto', $e);
        }
    }

    private function uploadImage($id)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'staff'])) {
            \App\Core\Response::forbidden('No tienes permisos para subir imagenes');
        }

        if (empty($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            \App\Core\Response::error('No se recibió imagen', 400);
        }

        $file = $_FILES['image'];
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowed)) {
            \App\Core\Response::error('Tipo de archivo no permitido', 422);
        }

        $uploadsDir = __DIR__ . '/../../uploads/products/' . $id;
        @mkdir($uploadsDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'product_' . $id . '_' . time() . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            \App\Core\Response::error('Error al mover el archivo', 500);
        }

        $db = \App\Core\Database::getInstance();

        try {
            $db->execute("ALTER TABLE products ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL AFTER description", []);
        } catch (\Exception $e) {
        }

        $imageUrl = '/uploads/products/' . $id . '/' . $filename;
        try {
            $db->execute('UPDATE products SET image_url = ?, updated_at = NOW() WHERE id = ?', [$imageUrl, $id]);
        } catch (\Exception $e) {
            error_log('Update product image error: ' . $e->getMessage());
        }

        if (class_exists('\\\App\\Core\\Audit')) {
            \App\Core\Audit::log('upload_product_image', 'product', $id, ['filename' => $filename]);
        }

        \App\Core\Response::success(['image_url' => $imageUrl], 'Imagen subida');
    }

    private function adjustStock($id, $input)
    {
        $user = \App\Core\Auth::getCurrentUser();
        if (!in_array($user['role'], ['superadmin', 'admin', 'staff'])) {
            \App\Core\Response::forbidden();
        }

        $validator = \App\Core\Validator::make($input, [
            'quantity' => 'required|integer',
            'type' => 'required|in:add,subtract,set'
        ]);

        try {
            $validator->validate();
        } catch (\Exception $e) {
            \App\Core\Response::validationError(['message' => $e->getMessage()]);
        }

        $db = \App\Core\Database::getInstance();
        $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);

        if (!$product) {
            \App\Core\Response::notFound('Producto no encontrado');
        }

        if (($product['type'] ?? null) === 'service') {
            \App\Core\Response::error('Los servicios no tienen inventario', 400);
        }

        try {
            $newStock = (int) ($product['stock'] ?? 0);

            switch ($input['type']) {
                case 'add':
                    $newStock += abs((int) $input['quantity']);
                    break;
                case 'subtract':
                    $newStock -= abs((int) $input['quantity']);
                    if ($newStock < 0) {
                        \App\Core\Response::error('Stock insuficiente', 400);
                    }
                    break;
                case 'set':
                    $newStock = abs((int) $input['quantity']);
                    break;
            }

            if ($newStock > 999999) {
                \App\Core\Response::error('Stock excede límite máximo', 400);
            }

            $db->execute(
                'UPDATE products SET stock = ?, updated_at = NOW() WHERE id = ?',
                [$newStock, $id]
            );

            if (class_exists('\\\App\\Core\\Audit')) {
                \App\Core\Audit::log('adjust_stock', 'product', $id, ['new_stock' => $newStock, 'type' => $input['type'], 'quantity' => $input['quantity']]);
            }

            $product = $db->fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
            \App\Core\Response::success($product, 'Stock actualizado exitosamente');
        } catch (\Exception $e) {
            \App\Core\Response::dbException('Error al ajustar stock', $e);
        }
    }
}

