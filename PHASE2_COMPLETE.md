# FASE 2: COMPLETADA ✅
## Login Blocking + Rate Limiting + 2FA (Opcional por Usuario)

**Status:** ✅ IMPLEMENTADO Y DESPLEGADO  
**Fecha:** 3 de Enero, 2026  
**Base de Datos:** crm_spa_medico (schemas aplicados)  
**Prioridad:** CRÍTICO

---

## 📊 RESUMEN EJECUTIVO

Se han implementado **3 componentes críticos de seguridad** que completan Phase 2:

| Componente | Status | Archivos | Features |
|-----------|--------|----------|----------|
| **Login Attempt Blocking** | ✅ Listo | 2 | Bloqueo automático, tracking intentos, auditoría |
| **Rate Limiting** | ✅ Listo | 3 | 100 req/min usuario, 1000 req/hora IP, headers RFC 6585 |
| **2FA Email-based** | ✅ Listo | 3 | Códigos 6 dígitos, backup codes, expiración 5 min |

**Tiempo Total de Implementación:** ~6 horas  
**Archivos Nuevos:** 8  
**Archivos Modificados:** 3  

---

## 🎯 LO QUE SE IMPLEMENTÓ

### 1️⃣ LOGIN ATTEMPT BLOCKING

**Archivos:**
- `backend/docs/phase2-login-blocking-schema.sql` - Schema de BD
- `backend/app/Core/LoginAttemptTracker.php` - Clase principal

**Características:**
- ✅ Tracking de todos los intentos de login (exitosos y fallidos)
- ✅ Bloqueo automático después de **5 intentos fallidos**
- ✅ Bloqueo temporal de **15 minutos**
- ✅ Registro de IP, user agent, timestamp
- ✅ Tabla `login_attempts` con índices optimizados
- ✅ Tabla `account_locks` para bloqueos activos
- ✅ Auto-limpieza de intentos > 24 horas
- ✅ Detección de IPs sospechosas (múltiples emails)
- ✅ Auditoría completa en `audit_logs`

**Integración:**
```php
// En AuthController::login()
$attemptTracker = new LoginAttemptTracker($db);

// Verificar bloqueo
if ($attemptTracker->isAccountLocked($email)) {
    $lockInfo = $attemptTracker->getLockInfo($email);
    Response::error("Cuenta bloqueada por {$lockInfo['minutes_remaining']} minutos", 423);
}

// Registrar intento fallido
$attemptTracker->recordAttempt($email, false, 'invalid_password');

// Registrar intento exitoso
$attemptTracker->recordAttempt($email, true);
```

**Respuestas HTTP:**
```
423 Locked - Cuenta temporalmente bloqueada
401 Unauthorized - Credenciales incorrectas (con contador si <3 intentos restantes)
```

---

### 2️⃣ RATE LIMITING

**Archivos:**
- `backend/docs/phase2-rate-limiting-schema.sql` - Schema de BD
- `backend/app/Core/RateLimiter.php` - Middleware principal
- `backend/public/index.php` - Integración en entry point

**Características:**
- ✅ **Límite por usuario autenticado:** 100 requests/minuto
- ✅ **Límite por IP:** 1000 requests/hora
- ✅ **Límite guest (no autenticado):** 20 requests/minuto
- ✅ **Límites especiales para endpoints críticos:**
  - `/api/v1/auth/login`: 5 req/min
  - `/api/v1/auth/register`: 3 req/5min
  - `/api/v1/auth/forgot-password`: 3 req/hora
- ✅ Headers RFC 6585:
  - `X-RateLimit-Limit`: Límite máximo
  - `X-RateLimit-Remaining`: Requests restantes
  - `X-RateLimit-Reset`: Timestamp de reset
  - `Retry-After`: Segundos hasta permitir nuevo request
- ✅ Tabla `rate_limits` con auto-limpieza cada 5 minutos
- ✅ Normalización de endpoints (IDs → `{id}`)
- ✅ Auditoría de rate limit exceeded

**Integración:**
```php
// En public/index.php (ANTES de routing)
$rateLimiter = new RateLimiter();
$userId = Auth::getUserIdFromToken();  // Si está autenticado

$result = $rateLimiter->handle($userId);

if ($result === false) {
    header('HTTP/1.1 429 Too Many Requests');
    echo json_encode(['error' => 'Too many requests']);
    exit;
}
```

**Respuestas HTTP:**
```
429 Too Many Requests - Excedió rate limit
Headers:
  X-RateLimit-Limit: 100
  X-RateLimit-Remaining: 0
  X-RateLimit-Reset: 1704304800
  Retry-After: 45
```

---

### 3️⃣ TWO-FACTOR AUTHENTICATION (2FA)

**Archivos:**
- `backend/docs/phase2-2fa-schema.sql` - Schema de BD
- `backend/app/Core/TwoFactorAuth.php` - Gestión de 2FA
- `backend/app/Controllers/AuthController.php` - Flujo de login con 2FA

**Características:**
- ✅ Códigos de **6 dígitos** enviados por email
- ✅ Validez de **5 minutos**
- ✅ **Backup codes** (10 códigos de recuperación)
- ✅ Formato backup: `XXXX-XXXX`
- ✅ Tabla `two_factor_codes` con tracking de verificaciones
- ✅ Tabla `two_factor_backup_codes` para recuperación
- ✅ Campo `users.two_factor_enabled` para activar/desactivar
- ✅ Método de 2FA configurable (email/totp/sms)
- ✅ Invalidación automática de códigos anteriores
- ✅ Auto-limpieza de códigos expirados cada 10 minutos
- ✅ Auditoría completa de activaciones, verificaciones, uso de backup codes

**Flujo de Login con 2FA:**
```
1. POST /api/v1/auth/login
   Body: { "email": "user@example.com", "password": "password" }

2. Response (si tiene 2FA):
   {
     "requires_2fa": true,
     "temp_token": "eyJ...",  // Token temporal (5 min)
     "message": "Se ha enviado código a su email",
     "expires_in": 300
   }

3. Usuario recibe email con código: 123456

4. POST /api/v1/auth/verify-2fa
   Body: { "code": "123456", "temp_token": "eyJ..." }

5. Response (si código correcto):
   {
     "token": "eyJ...",  // Token final normal
     "user": { ... }
   }
```

**Habilitación de 2FA:**
```php
$twoFA = new TwoFactorAuth($db);

// Habilitar
$twoFA->enable($userId, 'email');
$backupCodes = $twoFA->generateBackupCodes($userId);
// Retornar códigos al usuario para que los guarde

// Deshabilitar
$twoFA->disable($userId);

// Verificar status
$enabled = $twoFA->isEnabled($userId);
```

**Endpoints Nuevos:**
```
POST /api/v1/auth/verify-2fa - Verificar código 2FA
```

---

## 📂 ARCHIVOS CREADOS

### Schema SQL (3 archivos)
```
backend/docs/
├── phase2-login-blocking-schema.sql (150 líneas)
├── phase2-rate-limiting-schema.sql (100 líneas)
└── phase2-2fa-schema.sql (200 líneas)
```

### Core Classes (3 archivos)
```
backend/app/Core/
├── LoginAttemptTracker.php (400 líneas)
├── RateLimiter.php (500 líneas)
└── TwoFactorAuth.php (450 líneas)
```

### Modificados
```
backend/app/Controllers/AuthController.php
  ✓ Integrado LoginAttemptTracker en login()
  ✓ Integrado TwoFactorAuth flow
  ✓ Agregado método verify2FA()

backend/public/index.php
  ✓ Agregado RateLimiter middleware global

backend/routes/api.php
  ✓ Agregado route POST /api/v1/auth/verify-2fa
```

---

## 🗄️ CAMBIOS EN BASE DE DATOS

### Nuevas Tablas
```sql
-- Login Blocking
CREATE TABLE login_attempts (
    id, email, ip_address, user_agent, success, 
    failure_reason, created_at
);

CREATE TABLE account_locks (
    id, email, locked_at, locked_until, attempts_count,
    lock_reason, unlocked_at, unlocked_by
);

-- Rate Limiting
CREATE TABLE rate_limits (
    id, identifier, identifier_type, endpoint, 
    request_count, window_start, window_end, 
    created_at, updated_at
);

-- 2FA
CREATE TABLE two_factor_codes (
    id, user_id, code, method, ip_address, user_agent,
    verified, verified_at, expires_at, created_at
);

CREATE TABLE two_factor_backup_codes (
    id, user_id, code, used, used_at, created_at
);
```

### Modificaciones a Tablas Existentes
```sql
ALTER TABLE users 
ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0,
ADD COLUMN two_factor_method ENUM('email', 'totp', 'sms') DEFAULT 'email',
ADD COLUMN two_factor_secret VARCHAR(255) NULL;
```

### Procedimientos Almacenados
```sql
cleanup_old_login_attempts()      -- Limpia login_attempts > 24h
cleanup_expired_rate_limits()     -- Limpia rate_limits expirados
cleanup_expired_2fa_codes()       -- Limpia 2FA codes expirados
generate_backup_codes()           -- Genera códigos de recuperación
```

### Eventos Programados
```sql
cleanup_login_attempts_daily      -- Ejecuta cada 1 día
cleanup_rate_limits_every_5min    -- Ejecuta cada 5 minutos
cleanup_2fa_codes_every_10min     -- Ejecuta cada 10 minutos
```

---

## 🚀 DEPLOYMENT

### 1. Aplicar Schema SQL
```bash
# Ejecutar en orden:
mysql -u root -p crm < backend/docs/phase2-login-blocking-schema.sql
mysql -u root -p crm < backend/docs/phase2-rate-limiting-schema.sql
mysql -u root -p crm < backend/docs/phase2-2fa-schema.sql
```

### 2. Verificar Tablas Creadas
```sql
SHOW TABLES LIKE '%login%';
SHOW TABLES LIKE '%rate%';
SHOW TABLES LIKE '%two_factor%';

-- Verificar eventos programados
SHOW EVENTS;
```

### 3. Probar Funcionalidad

#### Login Blocking
```bash
# Intentar login 6 veces con password incorrecto
for i in {1..6}; do
  curl -X POST http://localhost/api/v1/auth/login \
    -d '{"email":"test@example.com","password":"wrong"}' \
    -H "Content-Type: application/json"
done

# El 6to intento debe retornar 423 Locked
```

#### Rate Limiting
```bash
# Hacer 101 requests rápidos
for i in {1..101}; do
  curl http://localhost/api/v1/patients \
    -H "Authorization: Bearer $TOKEN"
done

# Request 101 debe retornar 429 Too Many Requests
```

#### 2FA
```bash
# 1. Habilitar 2FA en BD manualmente
mysql -u root -p crm -e "UPDATE users SET two_factor_enabled=1 WHERE email='doctor@hospital.com'"

# 2. Login (recibirá requires_2fa=true)
curl -X POST http://localhost/api/v1/auth/login \
  -d '{"email":"doctor@hospital.com","password":"password"}' \
  -H "Content-Type: application/json"

# 3. Verificar email para obtener código

# 4. Verificar código
curl -X POST http://localhost/api/v1/auth/verify-2fa \
  -d '{"code":"123456","temp_token":"eyJ..."}' \
  -H "Content-Type: application/json"
```

---

## 📊 MEJORAS DE SEGURIDAD

### Antes de Phase 2
```
❌ Sin límite de intentos de login
❌ Sin rate limiting (vulnerable a DoS)
❌ Sin 2FA (solo password)
❌ Sin tracking de IPs sospechosas
❌ Sin bloqueo automático de ataques
```

### Después de Phase 2
```
✅ Bloqueo después de 5 intentos fallidos (15 min)
✅ Rate limiting por usuario (100/min) y por IP (1000/hora)
✅ 2FA opcional con códigos de 6 dígitos
✅ Detección de IPs con múltiples intentos
✅ Auditoría completa de intentos y bloqueos
✅ Backup codes para recuperación
✅ Headers RFC 6585 en responses
✅ Auto-limpieza de datos antiguos
```

---

## 🔒 CUMPLIMIENTO DE SEGURIDAD

### ✅ Items Completados (Phase 1 + 2)
- [x] Backups automatizados con encriptación AES-256 (Phase 1)
- [x] Control de exportación con auditoría (Phase 1)
- [x] **Login attempt blocking con auditoría (Phase 2)**
- [x] **Rate limiting global y por endpoint (Phase 2)**
- [x] **2FA con códigos por email (Phase 2)**
- [x] RBAC implementado en 12 controladores
- [x] Audit logging en acciones sensibles
- [x] Password hashing con bcrypt
- [x] JWT para autenticación
- [x] CORS configurado
- [x] Prepared statements (SQLi prevención)
- [x] Input validation y sanitización
- [x] HTTPS ready

### ⏳ Pendiente (Phase 3)
- [ ] Encriptación de campos sensibles (NIT, precios)
- [ ] IP logging en todas las tablas de auditoría
- [ ] Alertas en tiempo real para actividades sospechosas
- [ ] Dashboard de métricas de seguridad
- [ ] TOTP support (alternativa a email 2FA)
- [ ] SMS 2FA support

---

## 📞 SOPORTE Y TROUBLESHOOTING

### Problema: "Cuenta bloqueada" sin haber fallado
```sql
-- Ver bloqueos activos
SELECT * FROM account_locks 
WHERE unlocked_at IS NULL AND locked_until > NOW();

-- Desbloquear manualmente
UPDATE account_locks 
SET unlocked_at = NOW() 
WHERE email = 'user@example.com';
```

### Problema: Rate limit muy restrictivo
```php
// En RateLimiter.php, ajustar constantes:
const LIMIT_USER_PER_MINUTE = 200;  // Aumentar de 100 a 200
const LIMIT_IP_PER_HOUR = 2000;     // Aumentar de 1000 a 2000
```

### Problema: Código 2FA no llega por email
```sql
-- Verificar código generado
SELECT code, expires_at FROM two_factor_codes 
WHERE user_id = X AND verified = 0 
ORDER BY created_at DESC LIMIT 1;

-- Ver logs de Mailer
tail -f /var/log/php_errors.log | grep "2FA"
```

### Problema: Usuario perdió backup codes
```php
// Regenerar backup codes
$twoFA = new TwoFactorAuth($db);
$newCodes = $twoFA->generateBackupCodes($userId);
// Enviar por email seguro o mostrar una vez
```

---

## 📈 MÉTRICAS Y MONITOREO

### Consultas Útiles

#### Intentos de Login Fallidos (última hora)
```sql
SELECT email, ip_address, COUNT(*) as attempts, 
       MAX(created_at) as last_attempt
FROM login_attempts
WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY email, ip_address
HAVING attempts >= 3
ORDER BY attempts DESC;
```

#### IPs Sospechosas
```sql
SELECT ip_address, COUNT(DISTINCT email) as unique_emails,
       COUNT(*) as total_attempts
FROM login_attempts
WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
HAVING unique_emails >= 3
ORDER BY total_attempts DESC;
```

#### Usuarios con 2FA Habilitado
```sql
SELECT role, COUNT(*) as users_with_2fa
FROM users
WHERE two_factor_enabled = 1
GROUP BY role;
```

#### Rate Limits Excedidos (última hora)
```sql
SELECT * FROM audit_logs
WHERE action = 'RATE_LIMIT_EXCEEDED'
AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;
```

---

**Documento Generado:** 3 de Enero, 2026  
**Versión:** 1.0  
**Estado:** ✅ IMPLEMENTADO
