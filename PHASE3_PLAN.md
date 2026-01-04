# FASE 3: ENHANCEMENTS DE SEGURIDAD Y MONITOREO
## Encriptación + IP Logging + Alertas + Dashboard

**Status:** 🚀 EN DESARROLLO (3.1 COMPLETADO, 3.2 EN PROGRESO)  
**Fecha Inicio:** 3 de Enero, 2026  
**Áreas:** 4 principales (encriptación, logging, alertas, dashboard)  

---

## 📋 ROADMAP FASE 3

### ✅ 1️⃣ ENCRIPTACIÓN DE CAMPOS SENSIBLES (Priority: ALTA) - 80% COMPLETADO

**Campos a encriptar:**
- ✅ `products.price` – Costos de servicios/productos
- ✅ `patients.email` – Email (actualizado de NIT que no existe)
- ✅ `patients.phone` – Teléfono pacientes
- ✅ `users.phone` – Teléfonos usuarios
- 🟡 `users.password` – NO (ya hasheado, revisar si necesario)

**Implementación Completada:**
- ✅ Extendido `Crypto.php` con `encryptField()` / `decryptField()` / `hashField()` / `verifyHashField()`
- ✅ Creada clase `FieldEncryption.php` como wrapper de alto nivel
- ✅ Schema `phase3-encryption-schema.sql` aplicado a crm_spa_medico
- ✅ Tabla `encryption_migrations` para tracking migración
- ✅ Vista `v_encryption_status` para monitoreo
- ✅ Script `migrate-encrypt-fields.php` para encriptar datos existentes
- ✅ Documentación completa `PHASE3_ENCRYPTION_GUIDE.md`

**Implementación Pendiente:**
- ⏳ Ejecutar `php backend/tools/migrate-encrypt-fields.php` para migrar datos
- ⏳ Integración en Controllers (ProductsController, PatientsController, UsersController)
- ⏳ Validaciones de campos encriptados
- ⏳ Búsquedas por hash en endpoints GET

**Archivos:**
- ✅ `backend/app/Core/Crypto.php` – Métodos de cifrado extendidos
- ✅ `backend/app/Core/FieldEncryption.php` – NUEVO wrapper de campos
- ✅ `backend/docs/phase3-encryption-schema.sql` – Schema aplicado
- ✅ `backend/tools/migrate-encrypt-fields.php` – NUEVO script de migración
- ✅ `PHASE3_ENCRYPTION_GUIDE.md` – NUEVA documentación

**Clave**: AES-256-GCM con HMAC-SHA256, derivada de APP_SECRET

---

### ✅ 2️⃣ IP LOGGING EN AUDITORÍA (Priority: MEDIA-ALTA) - 100% COMPLETADO

**Objetivo:** Registrar IP de cliente en cada auditoría

**Cambios Completados:**
- ✅ Agregada columna `ip_address` a `audit_logs`
- ✅ Modificado `Audit::log()` para capturar IP automáticamente
- ✅ Helper `getClientIp()` con soporte para proxies (X-Forwarded-For, X-Real-IP, CloudFlare)
- ✅ Índices para búsquedas rápidas por IP
- ✅ Métodos `getByIP()` y `detectSuspiciousIPs()` agregados

**Análisis Disponible:**
```php
// Auditoría por IP específica (últimas 24 horas)
$audit = Audit::getByIP('192.168.1.100', 24);

// Detectar IPs sospechosas (múltiples usuarios)
$suspicious = Audit::detectSuspiciousIPs(3, 24); // 3+ usuarios
```

**Archivos:**
- ✅ `backend/app/Core/Audit.php` – Modificado para IP tracking
- ✅ `backend/docs/phase3-audit-ip-schema.sql` – Schema aplicado
- ✅ Base de datos `crm_spa_medico` – Actualizada

---

### 🟡 3️⃣ ALERTAS EN TIEMPO REAL (Priority: MEDIA) - 0% (DISEÑADO)

**Eventos críticos a alertar:**
- [ ] Borrado masivo (>10 registros en 1 minuto)
- [ ] Cambio de rol (promover a admin/superadmin)
- [ ] Fallo de 2FA múltiple (>3 intentos)
- [ ] Rate limit exceeded (por IP)
- [ ] Login desde IP nueva/sospechosa
- [ ] Bulk export de datos

**Implementación Pendiente:**
- [ ] Tabla `security_alerts` con schema
- [ ] Clase `AlertManager.php` con disparadores
- [ ] Integración en Controllers clave
- [ ] Email/SMS notifications
- [ ] Endpoint GET `/api/v1/security/alerts`

**Prioridad:** Media (después de encriptación/IP logging)

---

### 🟡 4️⃣ DASHBOARD DE SEGURIDAD (Priority: MEDIA) - 0% (DISEÑADO)

**Métricas a mostrar:**
- [ ] Login attempts (últimas 24h, últimas 7d)
- [ ] Rate limit events
- [ ] 2FA adoption %
- [ ] Encryption migration progress
- [ ] Suspicious IPs
- [ ] Security alerts feed
- [ ] Audit log summary

**Endpoint:**
- [ ] GET `/api/v1/security/metrics` – Retorna JSON con métricas
- [ ] Frontend widget en dashboard (Angular)

**Implementación Pendiente:**
- [ ] Clase `SecurityMetricsController.php`
- [ ] Métodos de aggregación en modelos
- [ ] Caching de métricas (5 min TTL)
- [ ] Componente Angular para dashboard

**Prioridad:** Media (después de alertas)

---

## 📊 ESTADO GENERAL PHASE 3
- [ ] Múltiples fallos 2FA (>3 intentos en 5 min)
- [ ] Rate limit excedido (>3 veces en 10 min)
- [ ] Acceso a datos sensibles (exportación, descarga batch)

**Implementación:**
- [ ] Crear tabla `security_alerts`
- [ ] AlertManager.php – Lógica de detección
- [ ] Webhook/email para notificaciones
- [ ] Dashboard para ver alertas recientes

**Archivos:**
- [ ] `backend/app/Core/AlertManager.php` – Clase principal
- [ ] `backend/docs/phase3-alerts-schema.sql` – Schema

---

### 4️⃣ DASHBOARD DE MÉTRICAS (Priority: MEDIA)
**Métricas de seguridad:**
- [ ] Endpoint `/api/v1/security/metrics` – Datos de seguridad
- [ ] Gráficas: intentos login, rate limit, alertas
- [ ] Tabla: usuarios activos, roles, 2FA habilitado
- [ ] Mapa de IPs atacantes (últimas 24h)
- [ ] Resumen de actividad por rol

**Archivos:**
- [ ] `backend/app/Controllers/SecurityMetricsController.php`
- [ ] Frontend component (Angular)

---

## 🎯 PRIORIDADES

**ALTA (Semana 1):**
1. IP Logging en audit_logs
2. Encriptación de campos (products.price, patients.nit)

**MEDIA (Semana 2):**
3. AlertManager para eventos críticos
4. Dashboard básico de métricas

**BAJA (Fase 3.5):**
- TOTP support (Google Authenticator)
- SMS/WhatsApp integration (Twilio)
- Advanced analytics

---

## 📊 DETALLES TÉCNICOS

### Encriptación (phase3-encryption)
```sql
-- Nuevas columnas para campos encriptados
ALTER TABLE products ADD COLUMN price_encrypted LONGBLOB NULL;
ALTER TABLE patients ADD COLUMN nit_encrypted LONGBLOB NULL;

-- Datos a migrar en segunda versión
```

### IP Logging (phase3-audit-ip)
```sql
ALTER TABLE audit_logs 
ADD COLUMN ip_address VARCHAR(45) NULL AFTER user_id;

CREATE INDEX idx_audit_ip ON audit_logs(ip_address, created_at);
```

### Alertas (phase3-alerts)
```sql
CREATE TABLE security_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL,  -- 'bulk_delete', 'role_change', etc
    severity ENUM('low', 'medium', 'high', 'critical'),
    user_id INT,
    resource_type VARCHAR(50),
    resource_id INT,
    details JSON,
    resolved TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## ✅ CHECKLIST PHASE 3

- [ ] IP Logging en audit_logs completado
- [ ] Encriptación de campos sensibles completada
- [ ] AlertManager implementado
- [ ] Dashboard de métricas funcional
- [ ] Tests de integración pasando
- [ ] Documentación actualizada
- [ ] Phase 3 COMPLETE.md creado

---

## 🚀 SIGUIENTES PASOS

1. ✅ Confirmar orden de prioridades con usuario
2. Comenzar con **IP Logging** (más rápido, costo bajo)
3. Continuar con **Encriptación** (más complejo)
4. Alertas y Dashboard según tiempo disponible
