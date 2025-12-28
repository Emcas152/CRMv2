# 🏥 Backend PHP Puro - CRM Spa Médico

Backend RESTful API construido en **PHP puro (sin frameworks)** para gestión de spa médico con sistema de roles, JWT y protección XSS.

## ✨ Características

- ✅ **PHP Puro** - Sin dependencias de frameworks (Laravel, Symfony, etc.)
- ✅ **JWT Authentication** - Autenticación basada en tokens
- ✅ **Protección XSS** - Sanitización automática de entradas
- ✅ **PDO** - Prepared statements contra SQL injection
- ✅ **API RESTful** - Endpoints JSON bien estructurados
- ✅ **Sistema de Roles** - admin, doctor, staff, patient
- ✅ **Headers de Seguridad** - CSP, X-XSS-Protection, etc.
- ✅ **CORS Configurado** - Para frontend separado
- ✅ **Router Simple** - Sistema de rutas básico pero funcional

## 📋 Requisitos

- PHP 8.0 o superior
- MySQL 5.7+ o MariaDB 10.3+
- Apache con mod_rewrite (o Nginx)
- Extensiones PHP:
  - PDO
  - PDO_MySQL
  - JSON
  - mbstring
  - openssl

## 🚀 Instalación

### 1. Clonar o Copiar Archivos

```bash
# Copiar la carpeta backend-php-puro a tu servidor
```

### 2. Configurar Variables de Entorno

```bash
cp .env.example .env
```

Edita `.env` con tus datos:

```env
DB_HOST=localhost
DB_DATABASE=crm_spa_medico
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña

APP_URL=https://tudominio.com
APP_SECRET=genera-una-clave-secreta-segura-aqui

CORS_ORIGINS=https://app.tudominio.com,https://tudominio.com
```

### 3. Crear Base de Datos

```sql
CREATE DATABASE crm_spa_medico CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ejecuta el script SQL (usa las mismas migraciones de Laravel):

```bash
# Desde tu proyecto Laravel
php artisan migrate --pretend > ../backend-php-puro/database.sql
```

O crea las tablas manualmente:

```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'doctor', 'staff', 'patient') DEFAULT 'patient',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    birthday DATE,
    address TEXT,
    qr_code VARCHAR(255),
    loyalty_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100),
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT DEFAULT 0,
    low_stock_alert INT DEFAULT 10,
    type ENUM('product', 'service') DEFAULT 'product',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE staff_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NOT NULL,
    position VARCHAR(255),
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    staff_member_id INT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    service VARCHAR(255) NOT NULL,
    status ENUM('scheduled', 'confirmed', 'completed', 'cancelled') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE SET NULL
);

-- Más tablas según necesites (sales, sale_items, patient_photos, patient_documents)
```

### 4. Crear Usuario Administrador

```sql
INSERT INTO users (name, email, password, role) 
VALUES ('Admin', 'admin@crmmedico.com', '$2y$10$YourHashedPasswordHere', 'admin');

-- Para generar hash de password:
```

```php
<?php
echo password_hash('admin123', PASSWORD_BCRYPT);
?>
```

### 5. Configurar Apache

#### Opción A: Configurar Virtual Host

```apache
<VirtualHost *:80>
    ServerName api.tudominio.com
    DocumentRoot /var/www/backend-php-puro/public
    
    <Directory /var/www/backend-php-puro/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/api-error.log
    CustomLog ${APACHE_LOG_DIR}/api-access.log combined
</VirtualHost>
```

#### Opción B: Usar Subcarpeta

Coloca todo en `/var/www/html/api/` y accede via `http://tudominio.com/api/`

### 6. Permisos

```bash
chmod -R 755 backend-php-puro
chmod -R 775 backend-php-puro/logs
chmod -R 775 backend-php-puro/uploads

# Crear carpetas necesarias
mkdir -p logs uploads
```

## 📚 Endpoints API

### Base URL

```
http://tudominio.com/api
```

### Autenticación

#### POST /login
```json
{
  "email": "admin@crmmedico.com",
  "password": "admin123"
}
```

Respuesta:
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "admin@crmmedico.com",
      "role": "admin"
    }
  }
}
```

#### POST /register
```json
{
  "name": "Juan Pérez",
  "email": "juan@example.com",
  "password": "Password123",
  "phone": "+52 123 456 7890",
  "birthday": "1990-01-15",
  "address": "Calle Principal 123"
}
```

#### GET /me
Headers: `Authorization: Bearer {token}`

#### POST /logout
Headers: `Authorization: Bearer {token}`

### Pacientes

#### GET /patients
Lista todos los pacientes (con filtrado por doctor)

Headers: `Authorization: Bearer {token}`

Query params:
- `search` - Búsqueda por nombre, email o teléfono

#### GET /patients/{id}
Ver un paciente específico

#### POST /patients
Crear nuevo paciente

```json
{
  "name": "María García",
  "email": "maria@example.com",
  "phone": "+52 123 456 7890",
  "birthday": "1985-05-20",
  "address": "Av. Reforma 456"
}
```

#### PUT /patients/{id}
Actualizar paciente

#### DELETE /patients/{id}
Eliminar paciente (solo admin)

#### POST /patients/{id}/loyalty/add
Añadir puntos de lealtad

```json
{
  "points": 100
}
```

#### POST /patients/{id}/loyalty/redeem
Canjear puntos

```json
{
  "points": 50
}
```

### Productos

#### GET /products
Listar productos

Query params:
- `type` - product o service
- `active` - true o false
- `search` - Búsqueda

#### GET /products/{id}
Ver producto

#### POST /products
Crear producto (admin/staff)

```json
{
  "name": "Botox 50U",
  "sku": "BOT-50",
  "description": "Botulinum toxin type A",
  "price": 4500.00,
  "stock": 10,
  "type": "product",
  "active": true
}
```

#### PUT /products/{id}
Actualizar producto

#### DELETE /products/{id}
Eliminar producto (solo admin)

#### POST /products/{id}/adjust-stock
Ajustar inventario

```json
{
  "quantity": 5,
  "type": "add"
}
```

Types: `add`, `subtract`, `set`

## 🔐 Autenticación

El sistema usa **JWT (JSON Web Tokens)** para autenticación.

### Cómo Funciona

1. Cliente hace POST a `/login` con credenciales
2. Servidor valida y retorna token JWT
3. Cliente incluye token en header `Authorization: Bearer {token}`
4. Servidor valida token en cada request protegido

### Estructura del Token

```json
{
  "user_id": 1,
  "email": "admin@crmmedico.com",
  "role": "admin",
  "iat": 1699000000,
  "exp": 1699086400
}
```

### Expiración

Por defecto 24 horas. Configurable en `config/app.php`:

```php
'jwt_expiration' => 86400, // 24 horas en segundos
```

## 🛡️ Seguridad

### Protecciones Implementadas

✅ **XSS** - Sanitización automática con `Sanitizer` class  
✅ **SQL Injection** - PDO con prepared statements  
✅ **CSRF** - No aplicable en API stateless con JWT  
✅ **Headers** - X-XSS-Protection, X-Frame-Options, etc.  
✅ **Password** - Bcrypt hashing con `password_hash()`  
✅ **JWT** - Firma HMAC-SHA256

### Sanitización

Todos los inputs son sanitizados automáticamente:

```php
// En public/index.php
$input = Sanitizer::input($input);
```

La clase `Sanitizer` elimina:
- Scripts y etiquetas peligrosas
- Eventos JavaScript inline
- Protocolos peligrosos (javascript:, vbscript:)
- Todas las etiquetas HTML

### Validación

```php
$validator = Validator::make($input, [
    'email' => 'required|email',
    'password' => 'required|string|min:8',
    'name' => 'required|string|max:255'
]);

if (!$validator->validate()) {
    Response::validationError($validator->getErrors());
}
```

## 📁 Estructura del Proyecto

```
backend-php-puro/
├── api/
│   ├── auth/
│   │   ├── login.php
│   │   ├── register.php
│   │   ├── me.php
│   │   └── logout.php
│   ├── patients/
│   │   └── index.php
│   ├── products/
│   │   └── index.php
│   ├── appointments/
│   │   └── index.php
│   └── sales/
│       └── index.php
├── config/
│   ├── app.php
│   └── database.php
├── core/
│   ├── Database.php
│   ├── Response.php
│   ├── Auth.php
│   ├── Validator.php
│   └── Sanitizer.php
├── public/
│   ├── index.php (Router)
│   └── .htaccess
├── logs/
│   └── error.log
├── uploads/
│   └── (archivos subidos)
├── .env.example
└── README.md
```

## 🔧 Diferencias con Laravel

| Laravel | PHP Puro |
|---------|----------|
| Eloquent ORM | PDO directo |
| Middleware Stack | Código directo en router |
| Blade Templates | No aplica (API JSON) |
| Artisan Commands | Scripts PHP directos |
| Service Container | Clases estáticas simples |
| Routing | Regex matching simple |

## 🚀 Ventajas del Backend PHP Puro

1. **Portabilidad** - Funciona en cualquier servidor con PHP+MySQL
2. **Sin Dependencias** - No require Composer ni vendor/
3. **Ligero** - ~10 archivos vs miles en Laravel
4. **Fácil Deploy** - Solo subir archivos por FTP
5. **Aprendizaje** - Entender cómo funciona todo por dentro

## ⚠️ Limitaciones

1. No tiene ORM (usa SQL directo)
2. No tiene migraciones automáticas
3. No tiene queue system
4. No tiene eventos/listeners
5. Router muy básico
6. Sin tests automatizados incluidos

## 📊 Rendimiento

- **Memoria**: ~2-5 MB por request
- **Tiempo**: ~50-100ms por request simple
- **Concurrencia**: Limitado por Apache/PHP-FPM

## 🔄 Migración desde Laravel

Si ya tienes el backend Laravel funcionando:

1. Usa la misma base de datos
2. Copia los datos de prueba
3. Configura `.env` con mismas credenciales
4. Cambia URL del API en frontend

## 📞 Soporte

- **Issues**: Revisar logs en `logs/error.log`
- **Debug**: Activar `display_errors` en desarrollo
- **Production**: Desactivar `display_errors` siempre

## 📄 Licencia

MIT License - Uso libre

---

**Desarrollado con ❤️ en PHP Puro**
