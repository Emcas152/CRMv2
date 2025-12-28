# Guía de integración Frontend (API REST)

Esta guía documenta **los endpoints reales** expuestos por este backend.

## Base URL

- Desarrollo (servidor embebido PHP): `http://127.0.0.1:8000/api/v1`
- Producción (según `.env.production.example`): `https://41252429.servicio-online.net/api/v1`

## Auth (JWT)

### Header

En todos los endpoints protegidos:

- `Authorization: Bearer <token>`
- `Content-Type: application/json` (solo para requests JSON)

### Formato de respuestas

La mayoría de endpoints usan el wrapper:

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {}
}
```

Errores:

```json
{
  "success": false,
  "message": "Error...",
  "errors": {"field": "detalle"}
}
```

Notas:
- Algunos endpoints responden **JSON sin wrapper** (p. ej. `POST /auth/login` usa `Response::json`).
- Algunos endpoints devuelven **archivos binarios** o **HTML** (documentos).

### Flujo recomendado

1. `POST /auth/login` → guardar `token`.
2. Usar `Authorization: Bearer <token>` en cada request.
3. `GET /auth/me` para cargar el usuario actual.

### Endpoints de Auth

- `POST /auth/login` (público)
  - Body JSON:
    - `email` (string)
    - `password` (string)
  - Respuesta (sin wrapper):
    ```json
    {
      "token": "...",
      "user": {"id": 1, "name": "...", "email": "...", "role": "admin", "email_verified": true}
    }
    ```

- `POST /auth/register` (público)
  - Crea un usuario con rol `patient` + registro en `patients`.
  - Body JSON:
    - `name` (string)
    - `email` (string)
    - `password` (string, min 8)
    - Opcionales: `phone`, `birthday`, `address`

- `GET /auth/me` (protegido)
  - Devuelve `{ user, patient? }`.

- `POST /auth/logout` (protegido)
  - No invalida JWT (solo responde OK).

- `GET|POST /auth/verify-email` (público)
  - Param: `token` (query o JSON)

- `GET /auth/debug-token` (debug)
  - Devuelve información de headers/token.

## Ejemplos de consumo (fetch)

### Request JSON

```js
const baseUrl = import.meta.env.VITE_API_URL; // ej: http://127.0.0.1:8000/api/v1

async function api(path, { method = 'GET', token, body } = {}) {
  const res = await fetch(`${baseUrl}${path}`, {
    method,
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body ? { 'Content-Type': 'application/json' } : {})
    },
    body: body ? JSON.stringify(body) : undefined
  });

  const contentType = res.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await res.json() : await res.text();

  if (!res.ok) throw { status: res.status, data };
  return data;
}
```

### Upload multipart (archivo)

```js
async function uploadDocument(token, file, patientId, title) {
  const form = new FormData();
  form.append('file', file);
  form.append('patient_id', String(patientId));
  if (title) form.append('title', title);

  const res = await fetch(`${baseUrl}/documents`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: form
  });

  const data = await res.json();
  if (!res.ok) throw { status: res.status, data };
  return data;
}
```

## Endpoints por módulo

### Patients

Base: `/patients`

- `GET /patients` (protegido)
  - Query params:
    - `search`, `date_from`, `date_to`, `birthday_month`
  - Respuesta: `{ data: [...], total: number }` (limit 20)

- `GET /patients/{id}` (protegido)

- `POST /patients` (protegido)
  - Roles: `admin|staff|doctor`
  - Body JSON: `name`, `email`, opcionales `phone`, `birthday`, `address`, `nit`

- `PUT /patients/{id}` (protegido)
  - Roles: `admin|staff|doctor`, o `patient` (solo su propio registro)

- `DELETE /patients/{id}` (protegido)
  - Rol: `admin`

- `GET /patients/{id}/qr` (protegido)
  - Devuelve `{ qr_code, qr_url }`.
  - Nota: actualmente `qr_url` puede venir `null` (el frontend debe renderizar el QR usando `qr_code`).

- `POST /patients/{id}/loyalty-add` (protegido)
  - Body JSON: `{ "points": 10 }`

- `POST /patients/{id}/loyalty-redeem` (protegido)
  - Body JSON: `{ "points": 10 }`
  - Si no alcanza: status `400` con mensaje `Puntos insuficientes`.

- `POST /patients/{id}/upload-photo` (protegido)
  - Roles: `admin|staff|doctor|patient` (patient: solo su propio paciente)
  - Multipart:
    - `photo` (archivo) o JSON `photo_base64`
    - `type`: `before|after` (default `before`)

### Products

Base: `/products`

- `GET /products` (protegido)
  - Query params: `type=product|service`, `active=true|false`, `search`
  - Respuesta: `{ data: [...], total }` (limit 20)

- `GET /products/{id}` (protegido)

- `POST /products` (protegido)
  - Roles: `admin|staff`
  - Body JSON: `name`, `price`, `type`, opcionales `sku`, `description`, `stock`, `active`

- `PUT /products/{id}` (protegido)
  - Roles: `admin|staff`

- `DELETE /products/{id}` (protegido)
  - Roles: `admin|staff`

- `POST /products/{id}/upload-image` (protegido)
  - Roles: `admin|staff`
  - Multipart: `image` (jpeg/png/gif)
  - Respuesta: `{ image_url }`

- `POST /products/{id}/adjust-stock` (protegido)
  - Roles: `admin|staff`
  - Body JSON:
    - `quantity` (int)
    - `type` = `add|subtract|set`
  - Nota: si `type=service` → error 400 `Los servicios no tienen inventario`.

### Documents

Base: `/documents`

- `GET /documents?patient_id={id}` (protegido)
  - Roles: `superadmin|admin|staff|doctor`.
  - Respuesta: `{ data: [...], total }`

- `POST /documents` (protegido)
  - Multipart:
    - `file` (obligatorio)
    - `patient_id` (obligatorio)
    - `title` (opcional)
  - Respuesta incluye URLs relativas:
    - `download_url`: `/api/v1/documents/{id}/download`
    - `file_url`: `/api/v1/documents/{id}/file`
    - `view_url`: `/api/v1/documents/{id}/view`

- `GET /documents/{id}/download` (protegido)
  - Devuelve JSON con `{ url, filename }` (la URL apunta a `/file`).

- `GET /documents/{id}/file` (protegido)
  - Devuelve el archivo (binario) con `Content-Type` real.

- `GET /documents/{id}/view` (protegido)
  - Devuelve HTML (viewer simple) que embebe el `/file`.

- `POST /documents/{id}/sign` (protegido)
  - Roles permitidos por código: `superadmin|admin|staff|doctor|patient`.
  - Multipart:
    - `signature` (archivo opcional)
    - `method` (opcional, default `manual`)
    - `meta` (opcional)

### Appointments

Base: `/appointments`

- `GET /appointments` (protegido)
  - Query params: `date`, `date_from`, `date_to`, `patient_id`, `staff_member_id`, `status`

- `GET /appointments/{id}` (protegido)

- `POST /appointments` (protegido)
  - Roles: `admin|staff`
  - Body JSON: `patient_id`, `appointment_date`, `appointment_time`, `service`, opcionales `staff_member_id`, `notes`, `status`

- `PUT /appointments/{id}` (protegido)
- `DELETE /appointments/{id}` (protegido)

Acciones:
- `POST /appointments/{id}/update-status` (protegido)
  - Body JSON: `{ "status": "pending|confirmed|completed|cancelled" }`

- `POST /appointments/{id}/send-email` (protegido)

- `POST /appointments/{id}/generate-reminder` (protegido)
  - Usa OpenAI (requiere `OPENAI_API_KEY`).

- `POST /appointments/{id}/send-whatsapp` (protegido)
  - Usa Twilio (requiere `TWILIO_*`).

### Sales

Base: `/sales`

- `GET /sales` (protegido)
  - Query params: `date_from`, `date_to`, `patient_id`, `status`, `payment_method`
  - Incluye `items` por cada venta.

- `GET /sales/{id}` (protegido)
  - Incluye `items`.

- `POST /sales` (protegido)
  - Body JSON:
    - `patient_id` (int)
    - `payment_method` = `cash|card|transfer|other`
    - `discount` (num, opcional)
    - `notes` (string, opcional)
    - `items` (array obligatorio), cada item:
      - `product_id` (int)
      - `price` (num)
      - `quantity` (int)
  - Nota: si el producto es `type=product`, valida stock.

- `PUT /sales/{id}` (protegido)
  - Campos: `status`, `payment_method`, `notes`

- `DELETE /sales/{id}` (protegido)
  - Restaura stock de items cuando corresponde.

### Users (administración)

Base: `/users`

- Requiere token y rol `admin|superadmin`.

- `GET /users?role=&search=`
- `GET /users/{id}`
- `POST /users` (crear)
  - Body: `name`, `email`, `password`, `role`, `phone?`
  - Solo `superadmin` puede crear `superadmin`.
- `PUT /users/{id}`
  - Permite cambiar `password` si se envía.
- `DELETE /users/{id}`
  - No permite borrar el usuario actual.

### Email templates

Base: `/email-templates`

- Requiere token y rol `admin|superadmin`.
- `GET /email-templates`
- `GET /email-templates/{id}`
- `POST /email-templates`
- `PUT /email-templates/{id}`
- `DELETE /email-templates/{id}`

### Profile

Base: `/profile`

- `GET /profile` (protegido)
  - Devuelve `{ user, patient?, staff_member? }` según rol.

- `PUT /profile` (protegido)
  - Actualiza `name` / `email`.

- `POST /profile/change-password` (protegido)
  - Body: `current_password`, `new_password`, `confirm_password`

- `POST /profile/upload-photo` (protegido)
  - (Endpoint separado del de pacientes; sirve para foto de perfil del usuario.)

### Dashboard

- `GET /dashboard/stats` (protegido)
  - Devuelve métricas agregadas (ingresos, citas, etc.).

- `GET /dashboard/debug-stats` (protegido)
  - Debug de token/tablas.

### QR

Base: `/qr`

- `POST /qr/scan` (público o con token)
  - Body JSON:
    - `qr_code` (string) (o query `?qr=`)
    - `action` = `none|add|redeem` (default `none`)
    - `points` (int; requerido si action != none)
  - Respuesta: paciente asociado al QR.

## Notas prácticas

- CORS está controlado por `CORS_ALLOWED_ORIGINS` (lista separada por comas). En producción conviene whitelistear tu dominio del frontend.
- Para endpoints que devuelven archivo (`/documents/{id}/file`) no intentes parsear JSON.
- Para listar recursos, el backend actualmente usa límites simples (ej. 20) sin paginación.
