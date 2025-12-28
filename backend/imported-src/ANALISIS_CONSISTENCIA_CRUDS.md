# 🔍 ANÁLISIS DE CONSISTENCIA DE CRUDs - Backend PHP Puro

**Fecha:** 2025-12-12  
**Comparación:** Users, Patients, Products, Appointments

---

## ✅ INCONSISTENCIAS ENCONTRADAS

### 1. **DELETE - Métodos de Eliminación**

#### ❌ INCONSISTENTE - Diferentes implementaciones:

**Users (api/users/index.php):**
```php
$db->delete('users', ['id' => $id]);
```

**Patients (api/patients/index.php):**
```php
$db->execute('DELETE FROM patients WHERE id = ?', [$id]);
```

**Products (api/products/index.php):**
```php
$db->execute('DELETE FROM products WHERE id = ?', [$id]);
```

**Appointments (api/appointments/index.php):**
```php
$db->execute('DELETE FROM appointments WHERE id = ?', [$id]);
```

**🔧 RECOMENDACIÓN:** Usar siempre `$db->execute()` con prepared statements para consistencia.

---

### 2. **UPDATE - Construcción de Queries**

#### ❌ INCONSISTENTE:

**Users (api/users/index.php):**
```php
$updateData = ['updated_at' => date('Y-m-d H:i:s')];
if (isset($input['name'])) $updateData['name'] = $input['name'];
// ...
$db->update('users', $updateData, ['id' => $id]);
```

**Patients, Products, Appointments:**
```php
$updates = [];
$params = [];
if (isset($input['name'])) {
    $updates[] = 'name = ?';
    $params[] = $input['name'];
}
$updates[] = 'updated_at = NOW()';
$params[] = $id;
$query = 'UPDATE table SET ' . implode(', ', $updates) . ' WHERE id = ?';
$db->execute($query, $params);
```

**🔧 RECOMENDACIÓN:** Usar el método manual (arrays) para tener control total sobre prepared statements.

---

### 3. **CREATE - Métodos de Inserción**

#### ❌ INCONSISTENTE:

**Users:**
```php
$userId = $db->insert('users', [
    'name' => $input['name'],
    'email' => $input['email'],
    // ...
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
]);
```

**Patients, Products, Appointments:**
```php
$db->execute(
    'INSERT INTO table (col1, col2, ..., created_at, updated_at) VALUES (?, ?, ..., NOW(), NOW())',
    [$value1, $value2, ...]
);
$id = $db->lastInsertId();
```

**🔧 RECOMENDACIÓN:** Usar `execute()` + `lastInsertId()` para consistencia.

---

### 4. **Respuestas de Creación**

#### ✅ CONSISTENTE - Todos retornan el objeto creado:

```php
Response::success($entity, 'Mensaje exitosamente', 201);
```

---

### 5. **Validación de Permisos**

#### ⚠️ PARCIALMENTE CONSISTENTE:

**Users:** `if (!in_array($authUser['role'], ['superadmin', 'admin']))`  
**Patients:** `if (!in_array($user['role'], ['admin', 'staff', 'doctor']))`  
**Products:** `if (!in_array($user['role'], ['admin', 'staff']))`  
**Appointments:** `if (!in_array($user['role'], ['admin', 'staff']))`

**✅ CORRECTO:** Cada endpoint tiene permisos específicos según su lógica de negocio.

---

### 6. **Manejo de Errores**

#### ✅ MAYORMENTE CONSISTENTE:

Todos usan:
```php
try {
    // operación
    Response::success(...);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    Response::error('Mensaje de error');
}
```

---

### 7. **Validaciones**

#### ✅ CONSISTENTE - Todos usan Validator:

```php
$validator = Validator::make($input, [
    'field' => 'required|rules'
]);

if (!$validator->validate()) {
    Response::validationError($validator->getErrors());
}
```

---

### 8. **Respuestas de Eliminación**

#### ✅ CONSISTENTE:

Todos usan:
```php
Response::success(null, 'Entidad eliminada exitosamente');
```

---

## 📊 RESUMEN DE CONSISTENCIA

| Aspecto | Estado | Nota |
|---------|--------|------|
| **GET List** | ✅ Consistente | Todos usan `fetchAll()` con filtros |
| **GET One** | ✅ Consistente | Todos usan `fetchOne()` |
| **POST Create** | ❌ Inconsistente | Users usa `insert()`, otros `execute()` |
| **PUT Update** | ❌ Inconsistente | Users usa `update()`, otros `execute()` |
| **DELETE** | ❌ Inconsistente | Users usa `delete()`, otros `execute()` |
| **Validación** | ✅ Consistente | Todos usan `Validator::make()` |
| **Respuestas** | ✅ Consistente | Formato uniforme con `Response` |
| **Try-Catch** | ✅ Consistente | Todos manejan excepciones |
| **Permisos** | ✅ Correcto | Cada endpoint según su lógica |
| **Logging** | ✅ Consistente | `error_log()` en todos |

---

## 🔧 RECOMENDACIONES DE ESTANDARIZACIÓN

### Prioridad ALTA:

1. **Estandarizar DELETE** - Usar `$db->execute('DELETE FROM ... WHERE id = ?', [$id])`
2. **Estandarizar UPDATE** - Usar construcción manual con arrays
3. **Estandarizar CREATE** - Usar `execute()` + `lastInsertId()`

### Código Recomendado:

```php
// CREATE (Estandarizado)
$db->execute(
    'INSERT INTO table (col1, col2, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
    [$val1, $val2]
);
$id = $db->lastInsertId();

// UPDATE (Estandarizado)
$updates = [];
$params = [];
if (isset($input['field'])) {
    $updates[] = 'field = ?';
    $params[] = $input['field'];
}
$updates[] = 'updated_at = NOW()';
$params[] = $id;
$query = 'UPDATE table SET ' . implode(', ', $updates) . ' WHERE id = ?';
$db->execute($query, $params);

// DELETE (Estandarizado)
$db->execute('DELETE FROM table WHERE id = ?', [$id]);
```

---

## ✅ CONCLUSIÓN

**Estado General:** 70% Consistente

**Principales Inconsistencias:**
- Métodos de base de datos (insert/update/delete vs execute)
- Users usa helpers de Database, otros usan SQL directo

**Puntos Fuertes:**
- Validaciones uniformes
- Manejo de errores consistente
- Respuestas JSON estandarizadas
- Logging apropiado

**Siguiente Paso:** Estandarizar Users para usar `execute()` en lugar de `insert()`/`update()`/`delete()`.
