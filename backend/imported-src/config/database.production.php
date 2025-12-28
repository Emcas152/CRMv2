<?php
/**
 * Configuración de Base de Datos - PRODUCCIÓN
 * 
 * INSTRUCCIONES:
 * 1. Renombra este archivo a: database.php
 * 2. Rellena los datos de tu base de datos de producción
 * 3. Sube al servidor en: backend/config/database.php
 */

return [
    // Host de la base de datos
    // Para servidores compartidos suele ser 'localhost'
    // Para servidores dedicados puede ser una IP
    'host' => 'localhost',
    
    // Puerto de MySQL (normalmente 3306)
    'port' => '3306',
    
    // Nombre de la base de datos
    // Busca en cPanel -> MySQL Databases
    'database' => 'NOMBRE_DE_TU_BASE_DE_DATOS',
    
    // Usuario de MySQL
    // Busca en cPanel -> MySQL Databases -> Current Users
    'username' => 'USUARIO_MYSQL',
    
    // Contraseña de MySQL
    // La que configuraste al crear el usuario
    'password' => 'CONTRASEÑA_MYSQL',
    
    // Charset (no cambiar)
    'charset' => 'utf8mb4'
];

/*
 * EJEMPLO REAL:
 * 
 * return [
 *     'host' => 'localhost',
 *     'port' => '3306',
 *     'database' => 'u123456_crm',
 *     'username' => 'u123456_admin',
 *     'password' => 'MiPassword123!',
 *     'charset' => 'utf8mb4'
 * ];
 */
