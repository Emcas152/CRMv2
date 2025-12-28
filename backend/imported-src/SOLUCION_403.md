# 🚨 Solución Error 403 Forbidden

## Problema
`GET https://41252429.servicio-online.net/crm/ 403 (Forbidden)`

## Causas Comunes

### 1. ⚠️ Falta archivo index
**Solución**: El servidor necesita un archivo `index.php` o `index.html`

Ya se agregó `index.php` en la raíz que redirige a `public/`

### 2. ⚠️ Permisos incorrectos
**Solución en cPanel > File Manager**:

```bash
Carpeta backend/        → 755
Carpeta backend/public/ → 755
Archivo index.php       → 644
Archivo .htaccess       → 644
```

Para cambiar permisos:
1. Click derecho en carpeta/archivo
2. "Change Permissions"
3. Establece los valores de arriba

### 3. ⚠️ .htaccess bloqueando acceso

**Verificar**: Que existe `.htaccess` en ambas ubicaciones:
- `/httpdocs/backend/.htaccess` (raíz)
- `/httpdocs/backend/public/.htaccess` (public)

**Acción**: Sube los archivos `.htaccess` actualizados

### 4. ⚠️ PHP deshabilitado en carpeta

**Verificar en cPanel**:
1. Busca "Selector de PHP" o "MultiPHP Manager"
2. Asegúrate que PHP 8.1+ está habilitado para el dominio
3. Verifica que está en modo "php-fpm" o "FastCGI"

### 5. ⚠️ Directorio incorrecto

**Verificar estructura**:
```
/httpdocs/
└── crm/          o   backend/
    ├── .htaccess
    ├── index.php
    ├── public/
    │   ├── .htaccess
    │   └── index.php
    ├── api/
    ├── config/
    └── core/
```

## 🔧 Pasos de Solución

### Paso 1: Verificar que existen los archivos

**Conéctate via FTP y verifica**:
```
✓ /httpdocs/crm/.htaccess
✓ /httpdocs/crm/index.php
✓ /httpdocs/crm/public/.htaccess
✓ /httpdocs/crm/public/index.php
```

### Paso 2: Verificar permisos

**En FileZilla**:
1. Click derecho en carpeta `crm`
2. "File permissions"
3. Marca: Read + Write (owner), Read (group y public)
4. Valor numérico: 755

### Paso 3: Probar acceso directo

Intenta acceder a:
```
https://41252429.servicio-online.net/crm/
https://41252429.servicio-online.net/crm/public/
https://41252429.servicio-online.net/crm/test.php
```

### Paso 4: Revisar logs de error

**En cPanel**:
1. Busca "Error Log" o "Logs"
2. Revisa las últimas líneas
3. Busca mensajes relacionados con 403

## 🎯 Solución Rápida

Si nada funciona, prueba esto:

### Opción A: Acceso directo a public/

Cambia la URL del frontend a:
```typescript
// environment.ts
apiUrl: 'https://41252429.servicio-online.net/crm/public/api'
```

### Opción B: Renombrar carpeta

Cambia el nombre de `crm/` a `backend/`:
```
/httpdocs/backend/
```

Y usa:
```typescript
apiUrl: 'https://41252429.servicio-online.net/backend/api'
```

### Opción C: Crear index.html temporal

Crea este archivo en `/httpdocs/crm/index.html`:
```html
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="0; url=public/" />
</head>
<body>
    <p>Redirigiendo...</p>
</body>
</html>
```

## 📞 Si Sigue Sin Funcionar

### Crea archivo de prueba

`/httpdocs/crm/test-access.php`:
```php
<?php
echo json_encode([
    'status' => 'ok',
    'message' => 'Backend PHP funcionando',
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE']
]);
```

Accede: `https://41252429.servicio-online.net/crm/test-access.php`

Si esto funciona, el problema es con `.htaccess` o permisos.

## ✅ Checklist Final

- [ ] Archivos `.htaccess` subidos correctamente
- [ ] Archivo `index.php` en raíz de backend
- [ ] Permisos 755 en carpetas, 644 en archivos
- [ ] PHP 8.1+ habilitado
- [ ] Estructura de carpetas correcta
- [ ] Sin espacios en nombres de carpetas
- [ ] URL correcta en frontend

## 🔍 Debug Adicional

### Ver qué está bloqueando

Renombra temporalmente `.htaccess` a `.htaccess.bak`:
```
/httpdocs/crm/.htaccess → .htaccess.bak
```

Si funciona, el problema está en las reglas de `.htaccess`.

### Verificar mod_rewrite

Crea `/httpdocs/crm/test-rewrite.php`:
```php
<?php
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    echo json_encode([
        'mod_rewrite' => in_array('mod_rewrite', $modules),
        'modules' => $modules
    ]);
} else {
    echo json_encode(['error' => 'No se puede verificar módulos']);
}
```

## 📧 Contactar Soporte Hostalia

Si nada funciona, contacta soporte con esta info:

```
Problema: Error 403 al acceder a /crm/
URL: https://41252429.servicio-online.net/crm/
Estructura: Backend PHP puro con carpeta public/
PHP Version requerida: 8.0+
Permisos aplicados: 755 carpetas, 644 archivos
```

---

**Tiempo estimado de solución**: 5-15 minutos
