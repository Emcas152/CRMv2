<?php
/**
 * Configuración General de la Aplicación
 */

return [
    'app_name' => getenv('APP_NAME') ?: 'CRM Spa Médico',
    'app_url' => getenv('APP_URL') ?: 'https://41252429.servicio-online.net',
    'app_env' => getenv('APP_ENV') ?: 'production',
    'timezone' => 'America/Guatemala',
    'locale' => 'es',
    
    // Seguridad
    'secret_key' => getenv('JWT_SECRET') ?: getenv('APP_SECRET') ?: '@Emcas152.',
    'jwt_expiration' => intval(getenv('JWT_EXPIRATION') ?: 604800), // 7 días en segundos (para desarrollo)
    
    // CORS
    // Allow localhost for dev and the production frontend domain
    'cors_origins' => getenv('CORS_ALLOWED_ORIGINS') ?: getenv('CORS_ORIGINS') ?: 'http://localhost:4200,https://41252429.servicio-online.net',
    
    // Email
    'mail_from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@crm.com',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'CRM Spa Médico',
    'clinic_phone' => getenv('CLINIC_PHONE') ?: '+502 1234-5678',
    'clinic_address' => getenv('CLINIC_ADDRESS') ?: 'Guatemala, Guatemala',
    
    // Uploads
    'upload_path' => __DIR__ . '/../uploads',
    'max_upload_size' => 10485760, // 10MB en bytes
    
    // Paginación
    'per_page' => 20,
];
