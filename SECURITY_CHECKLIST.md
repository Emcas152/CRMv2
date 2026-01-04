# 🔐 Checklist de Seguridad - CRM Médico SPA

**Fecha**: 3 de enero de 2026  
**Estado General**: � **MAYORMENTE IMPLEMENTADO** (9/10 áreas - Phase 2 Complete)

---

## 🧠 1. Seguridad de Acceso y Autenticación

### ✅ Implementado (Phase 2 Complete)
- **Hash fuerte**: bcrypt (`PASSWORD_BCRYPT`) en [Auth.php](backend/app/Core/Auth.php#L191)
- **JWT con expiración**: Tokens con `exp` claim, expiración configurable en [config/app.php](backend/config/app.php)
- **Validación en cada request**: `Auth::requireAuth()` en todos los endpoints protegidos
- **✨ 2FA / MFA**: Email-based con códigos de 6 dígitos en [TwoFactorAuth.php](backend/app/Core/TwoFactorAuth.php)
  - Códigos válidos por 5 minutos
  - 10 backup codes de recuperación
  - Auditoría completa de activaciones/verificaciones
- **✨ Bloqueo por intentos fallidos**: 5 intentos = bloqueo 15 min en [LoginAttemptTracker.php](backend/app/Core/LoginAttemptTracker.php)
  - Tracking de IP y user agent
  - Detección de IPs sospechosas
  - Auditoría de bloqueos

### ⚠️ Mejoras recomendadas
- [ ] **Políticas de contraseña**:
  - [ ] Longitud mínima >= 12 caracteres (actualmente 8)
  - [ ] Expiración (forzar cambio cada 90 días)
  - [ ] Historial (no permitir reutilización de últimas 5 contraseñas)
- [ ] **Refresh tokens** (solo JWT con exp, sin rotate)
- [ ] **Expiración por inactividad** (logout automático después de 30 min sin actividad)
- [ ] **TOTP support** (alternativa a email 2FA)

**Prioridad**: 🟢 **BAJA** - Crítico implementado (2FA + Login Blocking)

---

## 🧩 2. Autorización y Roles (RBAC)

### ✅ Implementado (mejora reciente)
- **Roles y permisos por rol**:
  - `superadmin`: Acceso total
  - `admin`: Usuarios, plantillas email, CRM completo
  - `doctor`: Pacientes, citas, documentos, ventas, perfil, QR
  - `staff`: Pacientes, citas, ventas, documentos, productos, perfil, QR
  - `patient`: Solo sus citas, compras, documentos, conversaciones con médicos, perfil
  
- **Helpers centralizados**: `Auth::requireAnyRole()`, `Auth::hasAnyRole()` en [Auth.php](backend/app/Core/Auth.php)
- **Principio de mínimo privilegio**: ✓ Validado y ajustado
- **Validación por endpoint**: Todos los controladores verifican rol antes de acción

### ⚠️ Mejoras recomendadas
- [ ] Documentar matriz de permisos en archivo centralizado (RBAC_MATRIX.md)
- [ ] Añadir permisos granulares por modelo (hoy es solo "por rol")

**Prioridad**: 🟢 **MEDIA** - Bien implementado, solo documentación

---

## 🗂️ 3. Seguridad a Nivel de Datos

### ✅ Implementado
- **Cifrado en reposo (AES-256-GCM)**:
  - Documentos: Soporta cifrado opcional en [Crypto.php](backend/app/Core/Crypto.php#L25)
  - Controlable por config: `documents_encrypt_at_rest`
  
- **Hash seguro**:
  - Contraseñas: bcrypt
  - Tokens: HMAC-SHA256
  
- **Consultas preparadas**: PDO con placeholders en [Database.php](backend/app/Core/Database.php)

### ❌ FALTA IMPLEMENTAR
- **Cifrado en tránsito**: HTTPS/TLS (asunto de **deploy/servidor**, no código)
- **Campos sensibles cifrados**:
  - [ ] Costos de servicios/productos
  - [ ] NIT de pacientes
  - [ ] Números de serie (si aplica)
  - [ ] SSO/credenciales integradas
  
- **Data masking** en logs (no guardar datos sensibles en auditoría)

**Prioridad**: 🟡 **MEDIA-ALTA** - HTTPS es obligatorio en prod

---

## 🧬 4. Protección Contra Fugas Internas (Auditoría)

### ✅ Implementado
- **Audit logs** en tabla `audit_logs`:
  - Registra: `user_id`, `action`, `resource_type`, `resource_id`, `meta`, `created_at`
  - Usado en múltiples acciones: crear/editar/eliminar pacientes, documentos, etc.
  - Clase [Audit.php](backend/app/Core/Audit.php)

### ⚠️ Mejoras recomendadas
- [ ] Registrar **IP del cliente** en logs (actualmente solo user_id)
- [ ] Registrar **acciones sensibles**: Exportaciones, descargas de documentos, cambios de estado
- [ ] Logs **no modificables** (append-only table o archivo externo)
- [ ] Rotación de logs (archiva después de 90 días)
- [ ] Alertas en tiempo real: Borrado masivo, cambio de rol de admin

**Prioridad**: 🟡 **MEDIA** - Bien iniciado, necesita completarse

---

## 📤 5. Control de Exportación de Datos

### ✅ Implementado (Phase 1 Complete)
- **✨ Endpoint de exportación PDF/CSV restringido por rol** en [ReportController.php](backend/app/Controllers/ReportController.php):
  - Solo superadmin/admin/doctor/staff pueden exportar
  - Patient role bloqueado (403 Forbidden)
- **✨ Auditoría de exportaciones** en `audit_logs`:
  - Registra user_id, format, exported_by, email, timestamp
- **✨ Marca de agua** en PDFs: "DOCUMENTO CONFIDENCIAL - [USERNAME]"
- **✨ Metadatos en exports**: Quién exportó, cuándo, desde qué rol
- **✨ Data masking**: Precio oculto a staff en productos

### ⚠️ Mejoras recomendadas
- [ ] TCPDF para PDFs nativos server-side (actualmente HTML)
- [ ] Cifrado de exports sensibles antes de descarga
- [ ] Límite de tamaño de export (prevent large data dumps)
- [ ] Watermark digital en archivos (DRM ligero)

**Prioridad**: 🟢 **BAJA** - Implementado y funcional
- [ ] Marca de agua: Usuario, fecha, documento sensitivo
- [ ] Límite de columnas exportables
- [ ] Deshabilitación de exportaciones masivas
- [ ] Logs de exportación (quién, qué, cuándo)

**Riesgo**: 🔴 **MUY ALTO** - Usuario técnico podría exportar todo sin control

**Ejemplo de implementación necesaria**:
```php
// ReportController.php
public function exportPatients() {
    $user = Auth::getCurrentUser();
    
    // Solo admin/superadmin pueden exportar
    Auth::requireAnyRole(['superadmin', 'admin'], 
        'No tienes permisos para exportar datos');
    
    // Registrar exportación
    Audit::log('export_patients', 'patients', null, [
        'format' => 'pdf',
        'row_count' => count($patients)
    ]);
    
    // Añadir marca de agua
    return generatePDF($patients, [
        'watermark' => $user['name'] . ' - ' . date('Y-m-d H:i:s'),
        'columns' => ['id', 'name', 'email'] // Excluir datos sensibles
    ]);
}
```

**Prioridad**: 🔴 **CRÍTICA** - Implementar inmediatamente

---

## 🌐 6. Seguridad de la API (Frontend + Backend)

### ✅ Implementado (Phase 2 Complete)
- **JWT con expiración corta**: Configurable en `config/app.php`
- **Validación en cada request**: `Auth::requireAuth()` presente
- **Validación estricta de inputs**: [Validator.php](backend/app/Core/Validator.php) con reglas por campo
- **CORS**: Configurado en [public/index.php](backend/public/index.php)
- **✨ Rate limiting** en [RateLimiter.php](backend/app/Core/RateLimiter.php):
  - 100 requests/min por usuario autenticado
  - 1000 requests/hora por IP
  - 20 requests/min para usuarios no autenticados
  - Límites especiales para endpoints críticos:
    - `/auth/login`: 5 req/min
    - `/auth/register`: 3 req/5min
    - `/auth/forgot-password`: 3 req/hora
  - Headers RFC 6585: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
  - Auditoría de rate limit exceeded

### ⚠️ Mejoras recomendadas
- [ ] **Refresh tokens**: Hoy es solo acceso token con expiration
  - Recomendación: Implementar refresh token con rotación

**Prioridad**: 🟢 **BAJA** - Rate limiting implementado

---

## 🛡️ 7. Protección Contra Ataques Comunes

### ✅ Implementado
- **SQL Injection**: PDO prepared statements
- **XSS**: Sanitización en [Sanitizer.php](backend/app/Core/Sanitizer.php)
  - `htmlspecialchars()`, strip tags, validación de eventos
  
- **File upload**: Validación de tipo MIME en [PatientsController](backend/app/Controllers/PatientsController.php#L310)

### ⚠️ PARCIALMENTE IMPLEMENTADO
- **CSRF**: No se ve implementación de tokens CSRF
  - Recomendación: Usar SameSite cookies (PHP 7.3+)
  
### ❌ FALTA IMPLEMENTAR
- [ ] **CSRF tokens** en formularios POST/PUT/DELETE
- [ ] **Rate limiting** (ver punto 6)
- [ ] **Content Security Policy (CSP)** headers

**Ejemplo de CSRF + CSP**:
```php
// response headers
header('X-CSRF-Token: ' . bin2hex(random_bytes(32)));
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\'');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
```

**Prioridad**: 🟡 **MEDIA** - Bien cubierto en lo principal

---

## 🧑‍💻 8. Seguridad por Dispositivo y Ubicación

### ❌ NO IMPLEMENTADO
- [ ] Restricción por IP/rango
- [ ] Acceso solo desde VPN o red interna
- [ ] Detección de dispositivo desconocido (geolocalización)
- [ ] Cierre de sesión remoto (invalidar tokens activos)

**Prioridad**: 🟢 **BAJA** - Para empresas grandes solo

---

## 🧾 9. Políticas y Cumplimiento

### ❌ NO IMPLEMENTADO
- [ ] Términos de uso del sistema
- [ ] Política de privacidad / GDPR
- [ ] Política de acceso y roles
- [ ] Cumplimiento ISO/IEC 27001
- [ ] Aceptación de uso por usuario

**Nota**: Esto es legal/empresa, no código. Pero **necesario documentarlo**.

**Prioridad**: 🟡 **MEDIA** - Legal/compliance

---

## 🔥 10. Backups y Recuperación

### ✅ Implementado (Phase 1 Complete)
- **✨ Backups automáticos cifrados** en [backup.ps1](tools/backup.ps1):
  - Database mysqldump + ZIP compression
  - Files backup (uploads, config, src)
  - AES-256-CBC encryption con PBKDF2
  - Rotación automática (90 días)
  - Logs completos en backup.log
  - Windows Task Scheduler integration
  - Setup wizard en [setup-backup-scheduler.ps1](tools/setup-backup-scheduler.ps1)

### ⚠️ Mejoras recomendadas
- [ ] Pruebas de restauración (DR plan)
- [ ] Backup offsite/cloud (actualmente solo local)
- [ ] Acceso restringido a backups (solo admin con encryption key)
- [ ] Alertas por email si backup falla

**Prioridad**: 🟢 **BAJA** - Implementado y funcional

**Checklist de Backup**:
```bash
# Script sugerido
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/backups/crm"

# 1. Backup BD
mysqldump -u root -p$MYSQL_PWD crm_spa_medico | \
  openssl enc -aes-256-cbc -salt -out "$BACKUP_DIR/db_$DATE.sql.enc"

# 2. Backup archivos
tar -czf - /var/www/crm/uploads | \
  openssl enc -aes-256-cbc -salt -out "$BACKUP_DIR/files_$DATE.tar.gz.enc"

# 3. Limpiar backups > 90 días
find $BACKUP_DIR -name "*.enc" -mtime +90 -delete
```

---

## 📊 Resumen de Prioridades

| Área | Estado | Prioridad | Acción |
|------|--------|-----------|--------|
| Autenticación | 🟡 Parcial | 🔴 ALTA | Implementar 2FA, bloqueo por intentos, expiración por inactividad |
| Autorización (RBAC) | ✅ Implementado | 🟢 MEDIA | Solo documentar |
| Datos (Cifrado) | 🟡 Parcial | 🟡 MEDIA | Cifrar campos sensibles (costos, NIT) |
| Auditoría | 🟡 Parcial | 🟡 MEDIA | Añadir IP, alertas en tiempo real |
| **Exportación de datos** | ❌ NO | 🔴 CRÍTICA | **IMPLEMENTAR AHORA** |
| API (Rate limiting) | 🟡 Parcial | 🟡 MEDIA | Implementar rate limiting |
| XSS/SQL Injection | ✅ Implementado | 🟢 MEDIA | Bien cubierto |
| Dispositivo/Ubicación | ❌ NO | 🟢 BAJA | Opcional (empresas grandes) |
| Políticas | ❌ NO | 🟡 MEDIA | Documentar legalmente |
| **Backups** | ❌ NO | 🔴 CRÍTICA | **IMPLEMENTAR AHORA** |

---

## 🚀 Plan de Acción Recomendado

### Fase 1 (Semana 1-2) - CRÍTICO
1. ✋ **Backups automatizados** + script de restauración
2. ✋ **Control de exportación** de datos (PDF/Excel con logs y marca de agua)
3. Configurar **HTTPS/TLS** en servidor

### Fase 2 (Semana 3-4) - IMPORTANTE
1. Implementar **2FA** (email o TOTP)
2. Rate limiting en API
3. Bloqueo por intentos fallidos de login

### Fase 3 (Semana 5-6) - MEJORA
1. Cifrar campos sensibles (costos, NIT)
2. Ampliar auditoría (IP, alertas)
3. Documentar políticas de acceso

### Fase 4 (Ongoing) - MANTENIMIENTO
1. Reviews de seguridad mensuales
2. Rotación de secrets (JWT key, DB password)
3. Pruebas de penetración (anual)

---

## 📝 Archivos Relacionados

- [Auth.php](backend/app/Core/Auth.php) - Autenticación y JWT
- [Audit.php](backend/app/Core/Audit.php) - Auditoría
- [Crypto.php](backend/app/Core/Crypto.php) - Cifrado
- [Validator.php](backend/app/Core/Validator.php) - Validación
- [Sanitizer.php](backend/app/Core/Sanitizer.php) - Sanitización
- [Documentos Permiso](backend/docs/schema.mysql.sql) - Schema con audit_logs

---

**Última revisión**: 3 de enero de 2026  
**Responsable**: Equipo de Seguridad
