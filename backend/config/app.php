<?php
/**
 * Configuración General de la Aplicación
 */

return [
    'app_name' => getenv('APP_NAME') ?: 'CRM',
    'app_url' => getenv('APP_URL') ?: 'http://localhost:8000',
    'app_env' => getenv('APP_ENV') ?: 'local',
    'timezone' => getenv('APP_TIMEZONE') ?: 'America/Guatemala',
    'locale' => getenv('APP_LOCALE') ?: 'es',

    // Seguridad
    'secret_key' => getenv('JWT_SECRET') ?: getenv('APP_SECRET') ?: 'change-me',
    'jwt_expiration' => intval(getenv('JWT_EXPIRATION') ?: 604800),

    // CORS
    'cors_origins' => getenv('CORS_ALLOWED_ORIGINS') ?: getenv('CORS_ORIGINS') ?: '*',

    // Email
    'mail_from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@crm.com',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: (getenv('APP_NAME') ?: 'CRM'),
    'clinic_phone' => getenv('CLINIC_PHONE') ?: null,
    'clinic_address' => getenv('CLINIC_ADDRESS') ?: null,

    // Uploads
    'upload_path' => __DIR__ . '/../uploads',
    'max_upload_size' => 10485760,

    // Documents: signed URLs + encryption at rest
    // If enabled, uploaded files are stored encrypted (AES-256-GCM) and served decrypted.
    'documents_encrypt_at_rest' => filter_var(getenv('DOCUMENTS_ENCRYPT_AT_REST') ?: false, FILTER_VALIDATE_BOOLEAN),
    // TTL for signed download URLs returned by GET /api/v1/documents/{id}/download
    'signed_url_ttl_seconds' => intval(getenv('SIGNED_URL_TTL_SECONDS') ?: 600),

    // Paginación
    'per_page' => 20,
];
