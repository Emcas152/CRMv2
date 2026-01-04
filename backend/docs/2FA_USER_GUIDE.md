# 🔐 2FA Opcional - Guía de Usuario

## Resumen

El sistema de autenticación de dos factores (2FA) es **completamente opcional** y configurable por cada usuario.

### Características Principales

✅ **Opcional**: Cada usuario decide si activar o no el 2FA  
✅ **Múltiples métodos**: Email, SMS, WhatsApp  
✅ **Códigos de respaldo**: 10 códigos de emergencia  
✅ **Gestión completa**: Activar, desactivar, cambiar método

---

## 📋 Métodos Disponibles

### 1. Email (✅ Disponible ahora)
- Recibe códigos de 6 dígitos por email
- No requiere configuración adicional
- Válido por 5 minutos

### 2. SMS (⚙️ Requiere configuración)
- Recibe códigos por mensaje de texto
- **Requiere**: Configurar Twilio o proveedor SMS
- **Estado**: Implementado pero deshabilitado hasta configurar

### 3. WhatsApp (⚙️ Requiere configuración)
- Recibe códigos por WhatsApp
- **Requiere**: WhatsApp Business API
- **Estado**: Implementado pero deshabilitado hasta configurar

---

## 🚀 Endpoints Disponibles

### 1. Ver Estado del 2FA
```bash
GET /api/v1/2fa/status
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
  "enabled": false,
  "method": null,
  "email": "usuario@example.com",
  "phone": "+1234567890",
  "backup_codes_available": 0,
  "available_methods": ["email", "sms", "whatsapp"]
}
```

---

### 2. Listar Métodos Disponibles
```bash
GET /api/v1/2fa/methods
```

**Respuesta:**
```json
{
  "methods": [
    {
      "id": "email",
      "name": "Correo Electrónico",
      "description": "Recibir código por email",
      "available": true,
      "icon": "📧"
    },
    {
      "id": "sms",
      "name": "SMS",
      "description": "Recibir código por mensaje de texto",
      "available": false,
      "icon": "📱",
      "requires": "Configuración de Twilio o proveedor SMS"
    },
    {
      "id": "whatsapp",
      "name": "WhatsApp",
      "description": "Recibir código por WhatsApp",
      "available": false,
      "icon": "💬",
      "requires": "WhatsApp Business API"
    }
  ]
}
```

---

### 3. Activar 2FA
```bash
POST /api/v1/2fa/enable
Authorization: Bearer {token}
Content-Type: application/json

{
  "method": "email",
  "recipient": "usuario@example.com"  // Opcional si ya está en el perfil
}
```

**Respuesta:**
```json
{
  "message": "2FA activado correctamente",
  "method": "email",
  "backup_codes": [
    "1234-5678",
    "2345-6789",
    "3456-7890",
    "4567-8901",
    "5678-9012",
    "6789-0123",
    "7890-1234",
    "8901-2345",
    "9012-3456",
    "0123-4567"
  ],
  "warning": "⚠️ Guarde estos códigos de respaldo en un lugar seguro. No se mostrarán nuevamente."
}
```

**⚠️ IMPORTANTE**: Los códigos de respaldo solo se muestran UNA VEZ. Guárdalos en un lugar seguro.

---

### 4. Desactivar 2FA
```bash
POST /api/v1/2fa/disable
Authorization: Bearer {token}
Content-Type: application/json

{
  "password": "tu_contraseña_actual"
}
```

**Respuesta:**
```json
{
  "message": "2FA desactivado correctamente"
}
```

---

### 5. Probar Envío de Código
```bash
POST /api/v1/2fa/test
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
  "message": "Código enviado correctamente",
  "method": "email",
  "recipient": "us****@example.com",
  "expires_in_minutes": 5
}
```

---

### 6. Regenerar Códigos de Respaldo
```bash
POST /api/v1/2fa/regenerate-backup-codes
Authorization: Bearer {token}
Content-Type: application/json

{
  "password": "tu_contraseña_actual"
}
```

**Respuesta:**
```json
{
  "message": "Códigos de respaldo regenerados",
  "backup_codes": [
    "9876-5432",
    "8765-4321",
    ...
  ],
  "warning": "⚠️ Los códigos anteriores ya no son válidos. Guarde estos nuevos códigos."
}
```

---

## 🔄 Flujo de Login con 2FA

### Caso 1: Usuario SIN 2FA

```bash
POST /api/v1/auth/login
{
  "email": "usuario@example.com",
  "password": "password123"
}

# Respuesta directa con token
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": { ... }
}
```

### Caso 2: Usuario CON 2FA

**Paso 1: Login inicial**
```bash
POST /api/v1/auth/login
{
  "email": "usuario@example.com",
  "password": "password123"
}

# Respuesta con temp_token
{
  "requires_2fa": true,
  "temp_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "message": "Se ha enviado un código de verificación a su email",
  "method": "email",
  "expires_in": 300
}
```

**Paso 2: Verificar código**
```bash
POST /api/v1/auth/verify-2fa
{
  "temp_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "code": "123456"
}

# Respuesta con token final
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": { ... }
}
```

**Alternativa: Usar Backup Code**
```bash
POST /api/v1/auth/verify-2fa
{
  "temp_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "code": "1234-5678"  # Código de respaldo
}
```

---

## 📱 Guía de Implementación Frontend

### 1. Pantalla de Configuración de 2FA

```typescript
// Obtener estado del 2FA
async function get2FAStatus() {
  const response = await fetch('/api/v1/2fa/status', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  return await response.json();
}

// Listar métodos disponibles
async function get2FAMethods() {
  const response = await fetch('/api/v1/2fa/methods');
  return await response.json();
}

// Activar 2FA
async function enable2FA(method: 'email' | 'sms' | 'whatsapp') {
  const response = await fetch('/api/v1/2fa/enable', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ method })
  });
  
  const data = await response.json();
  
  // MOSTRAR backup_codes al usuario y pedirle que los guarde
  alert('Guarde estos códigos: ' + data.backup_codes.join(', '));
  
  return data;
}

// Desactivar 2FA
async function disable2FA(password: string) {
  const response = await fetch('/api/v1/2fa/disable', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ password })
  });
  return await response.json();
}
```

### 2. Flujo de Login con 2FA

```typescript
async function login(email: string, password: string) {
  const response = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  
  const data = await response.json();
  
  if (data.requires_2fa) {
    // Mostrar pantalla de ingreso de código
    show2FACodeInput(data.temp_token, data.method);
  } else {
    // Login exitoso, guardar token
    localStorage.setItem('token', data.token);
    navigateToHome();
  }
}

async function verify2FACode(tempToken: string, code: string) {
  const response = await fetch('/api/v1/auth/verify-2fa', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      temp_token: tempToken, 
      code: code 
    })
  });
  
  const data = await response.json();
  
  if (data.token) {
    localStorage.setItem('token', data.token);
    navigateToHome();
  } else {
    showError('Código incorrecto');
  }
}
```

---

## 🔧 Configuración de SMS y WhatsApp (Administradores)

### SMS con Twilio

1. **Obtener credenciales de Twilio**:
   - Account SID
   - Auth Token
   - Número de teléfono Twilio

2. **Configurar variables de entorno** (`.env` o servidor):
   ```env
   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_AUTH_TOKEN=your_auth_token_here
   TWILIO_PHONE_NUMBER=+1234567890
   ```

3. **Instalar SDK de Twilio**:
   ```bash
   composer require twilio/sdk
   ```

4. **Descomentar código en TwoFactorAuth.php**:
   - Líneas en `sendCodeBySMS()`: Descomentar integración con Twilio
   - Cambiar `return false;` a `return true;`

5. **Actualizar TwoFactorController.php**:
   - Remover validación que bloquea SMS (líneas 163-168)

### WhatsApp con Twilio

1. **Activar WhatsApp en Twilio**:
   - Solicitar acceso a WhatsApp API
   - Obtener número de WhatsApp de Twilio

2. **Configurar variables**:
   ```env
   TWILIO_WHATSAPP_NUMBER=+14155238886
   ```

3. **Descomentar código en TwoFactorAuth.php**:
   - Líneas en `sendCodeByWhatsApp()`: Descomentar integración
   - Cambiar `return false;` a `return true;`

4. **Actualizar TwoFactorController.php**:
   - Remover validación que bloquea WhatsApp

---

## 🧪 Testing

### Test 1: Activar 2FA por Email
```bash
# 1. Login normal
curl -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com", "password": "password123"}'

# Obtener token

# 2. Activar 2FA
curl -X POST http://localhost/api/v1/2fa/enable \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"method": "email"}'

# Guardar los backup_codes de la respuesta

# 3. Logout y login nuevamente
curl -X POST http://localhost/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com", "password": "password123"}'

# Ahora debe devolver requires_2fa: true

# 4. Verificar código (revisar email)
curl -X POST http://localhost/api/v1/auth/verify-2fa \
  -H "Content-Type: application/json" \
  -d '{"temp_token": "{temp_token}", "code": "123456"}'
```

### Test 2: Probar Backup Code
```bash
# Usar un backup code en lugar del código de email
curl -X POST http://localhost/api/v1/auth/verify-2fa \
  -H "Content-Type: application/json" \
  -d '{"temp_token": "{temp_token}", "code": "1234-5678"}'
```

### Test 3: Desactivar 2FA
```bash
curl -X POST http://localhost/api/v1/2fa/disable \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"password": "password123"}'
```

---

## ⚠️ Consideraciones de Seguridad

1. **Códigos de Respaldo**:
   - Se muestran SOLO UNA VEZ al activar 2FA
   - Deben guardarse en lugar seguro
   - Cada código es de un solo uso
   - Regenerar si se pierden (requiere contraseña)

2. **Rate Limiting**:
   - El endpoint `/auth/verify-2fa` tiene límite de 5 intentos/minuto
   - Después de 3 intentos fallidos, el código se invalida
   - Se requiere solicitar nuevo código

3. **Desactivación**:
   - Requiere confirmar con contraseña actual
   - Se audita en `audit_logs`
   - Se eliminan todos los códigos guardados

4. **Tokens Temporales**:
   - Válidos solo por 5 minutos
   - Solo sirven para verificar 2FA
   - No permiten acceso a otros endpoints

---

## 📊 Auditoría

Todos los eventos de 2FA se registran en `audit_logs`:

```sql
SELECT * FROM audit_logs 
WHERE action IN (
  '2FA_ENABLED',
  '2FA_DISABLED',
  '2FA_VERIFICATION_SUCCESS',
  '2FA_VERIFICATION_FAILED',
  '2FA_BACKUP_CODE_USED'
)
ORDER BY created_at DESC;
```

---

## 🆘 Soporte

### Usuario perdió acceso al 2FA

**Opción 1: Usar Backup Code**
- El usuario debe ingresar uno de los 10 códigos de respaldo

**Opción 2: Administrador desactiva 2FA**
```sql
-- Solo ejecutar si el usuario no tiene backup codes
UPDATE users 
SET two_factor_enabled = 0 
WHERE id = {user_id};

-- Limpiar códigos
DELETE FROM two_factor_codes WHERE user_id = {user_id};
DELETE FROM two_factor_backup_codes WHERE user_id = {user_id};
```

### No llegan los códigos por email

1. Verificar configuración de `Mailer`
2. Revisar logs: `error_log` para errores de envío
3. Probar endpoint `/api/v1/2fa/test` para debugging

---

## 📈 Métricas

```sql
-- Usuarios con 2FA habilitado
SELECT 
  two_factor_method,
  COUNT(*) as users_count
FROM users
WHERE two_factor_enabled = 1
GROUP BY two_factor_method;

-- Intentos de 2FA (últimas 24 horas)
SELECT 
  verified,
  COUNT(*) as attempts
FROM two_factor_codes
WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY verified;

-- Backup codes usados
SELECT COUNT(*) as used_backup_codes
FROM two_factor_backup_codes
WHERE used = 1;
```

---

## 🎯 Roadmap

- [x] Email 2FA
- [ ] SMS 2FA (implementado, requiere configuración)
- [ ] WhatsApp 2FA (implementado, requiere configuración)
- [ ] TOTP (Google Authenticator) - Fase 3
- [ ] Alertas de login desde nuevos dispositivos
- [ ] Trust device (recordar por 30 días)
