<?php

namespace App\Core;

use PDOException;

/**
 * FieldEncryption - Wrapper para encripción de campos a nivel de base de datos
 * 
 * Proporciona métodos para encriptar/desencriptar valores de campos específicos
 * Mantiene sincronización con columnas encrypted + hash para búsquedas sin desencriptar
 * 
 * Uso:
 *   // Encriptar valor nuevo
 *   $encrypted = FieldEncryption::encryptValue('12345');
 *   $hash = FieldEncryption::hashValue('12345');
 *   // INSERT INTO patients (nit_encrypted, nit_hash) VALUES (?, ?)
 *   
 *   // Desencriptar valor existente
 *   $nit = FieldEncryption::decryptValue($encrypted_blob);
 *   
 *   // Buscar por valor sin desencriptar
 *   $hash = FieldEncryption::hashValue('12345');
 *   // SELECT * FROM patients WHERE nit_hash = ?
 */
class FieldEncryption
{
    /**
     * Tipos de campos sensibles soportados
     */
    const TYPE_PRICE = 'price';
    const TYPE_NIT = 'nit';
    const TYPE_PHONE = 'phone';
    const TYPE_EMAIL = 'email';
    const TYPE_DOCUMENT = 'document';
    const TYPE_PASSPORT = 'passport';
    
    /**
     * Mapeo de tipos a columnas de hash para búsquedas
     */
    protected static $hashColumns = [
        self::TYPE_NIT => 'nit_hash',
        self::TYPE_PHONE => 'phone_hash',
        self::TYPE_EMAIL => 'email_hash',
        self::TYPE_DOCUMENT => 'document_hash',
        self::TYPE_PASSPORT => 'passport_hash',
    ];
    
    /**
     * Mapeo de tipos a columnas encriptadas
     */
    protected static $encryptedColumns = [
        self::TYPE_PRICE => 'price_encrypted',
        self::TYPE_NIT => 'nit_encrypted',
        self::TYPE_PHONE => 'phone_encrypted',
        self::TYPE_EMAIL => 'email_encrypted',
        self::TYPE_DOCUMENT => 'document_encrypted',
        self::TYPE_PASSPORT => 'passport_encrypted',
    ];
    
    /**
     * Encripta un valor usando Crypto
     * 
     * @param mixed $value Valor a encriptar (null, string, número)
     * @return string Valor encriptado en formato BLOB
     * @throws \RuntimeException Si hay error en encriptación
     */
    public static function encryptValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        return Crypto::encryptField($value);
    }
    
    /**
     * Desencripta un valor usando Crypto
     * 
     * @param string $encrypted Valor encriptado (BLOB)
     * @return string Valor original desencriptado
     * @throws \RuntimeException Si hay error en desencriptación
     */
    public static function decryptValue(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }
        
        return Crypto::decryptField($encrypted);
    }
    
    /**
     * Genera hash SHA256 para búsqueda sin desencriptar
     * 
     * Útil para:
     * - Buscar por NIT sin desencriptar
     * - Validar unicidad de email encriptado
     * - Detectar duplicados
     * 
     * @param string $value Valor original
     * @return string SHA256 hash (64 caracteres)
     */
    public static function hashValue(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        return Crypto::hashField(trim($value));
    }
    
    /**
     * Verifica si un valor coincide con su hash
     * 
     * @param string $value Valor original
     * @param string $hash Hash para comparar
     * @return bool True si coinciden
     */
    public static function verifyHash(string $value, string $hash): bool
    {
        if (empty($value) || empty($hash)) {
            return false;
        }
        
        return Crypto::verifyHashField($value, $hash);
    }
    
    /**
     * Obtiene el nombre de la columna encriptada para un tipo
     * 
     * @param string $fieldType Uno de las constantes TYPE_*
     * @return string Nombre de columna en BD (ej: 'nit_encrypted')
     */
    public static function getEncryptedColumn(string $fieldType): string
    {
        return self::$encryptedColumns[$fieldType] ?? null;
    }
    
    /**
     * Obtiene el nombre de la columna hash para un tipo
     * 
     * @param string $fieldType Uno de las constantes TYPE_*
     * @return string|null Nombre de columna en BD (ej: 'nit_hash') o null si no aplica
     */
    public static function getHashColumn(string $fieldType): ?string
    {
        return self::$hashColumns[$fieldType] ?? null;
    }
    
    /**
     * Verifica si un tipo de campo requiere columna hash para búsquedas
     * 
     * @param string $fieldType Uno de las constantes TYPE_*
     * @return bool True si este tipo tiene columna hash
     */
    public static function hasHashColumn(string $fieldType): bool
    {
        return isset(self::$hashColumns[$fieldType]);
    }
    
    /**
     * Encripta un campo y su hash para INSERT/UPDATE
     * Retorna array listo para usar en prepared statement
     * 
     * Ejemplo:
     *   $values = FieldEncryption::encryptFieldWithHash('12345', 'nit');
     *   // $values = ['nit_encrypted' => '...', 'nit_hash' => '...']
     *   // Luego: $db->insert('patients', $values);
     * 
     * @param mixed $value Valor a encriptar
     * @param string $fieldType Tipo de campo (ej: self::TYPE_NIT)
     * @return array Asociativo [encrypted_col => valor, hash_col => hash]
     */
    public static function encryptFieldWithHash($value, string $fieldType): array
    {
        $result = [];
        
        // Siempre establecer columna encriptada
        $encCol = self::getEncryptedColumn($fieldType);
        if ($encCol) {
            $result[$encCol] = self::encryptValue($value);
        }
        
        // Si aplica, establecer columna hash
        $hashCol = self::getHashColumn($fieldType);
        if ($hashCol) {
            $result[$hashCol] = self::hashValue($value);
        }
        
        return $result;
    }
    
    /**
     * Valida datos antes de encriptación
     * 
     * @param mixed $value Valor a validar
     * @param string $fieldType Tipo de campo
     * @return bool True si es válido
     */
    public static function validateValue($value, string $fieldType): bool
    {
        if ($value === null || $value === '') {
            return true; // Nulos permitidos
        }
        
        // Validaciones específicas por tipo
        switch ($fieldType) {
            case self::TYPE_NIT:
                // NIT: 5-20 caracteres, alfanuméricos + guiones
                return preg_match('/^[a-zA-Z0-9\-]{5,20}$/', (string)$value) === 1;
            
            case self::TYPE_PHONE:
                // Teléfono: 7-20 dígitos
                return preg_match('/^[\d+\-\s()]{7,20}$/', (string)$value) === 1;
            
            case self::TYPE_EMAIL:
                // Email válido
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            
            case self::TYPE_PRICE:
                // Número positivo
                return is_numeric($value) && (float)$value >= 0;
            
            default:
                return true; // Sin validación específica
        }
    }
    
    /**
     * Registra migración de encriptación en tabla tracking
     * 
     * @param \PDO $db Conexión PDO
     * @param string $tableName Tabla migrada
     * @param string $columnName Columna migrada
     * @param string $status 'pending', 'in_progress', 'completed', 'failed'
     * @param int $progress Porcentaje 0-100
     * @param string $error Mensaje de error si aplica
     * @return bool True si éxito
     */
    public static function logMigration(
        \PDO $db,
        string $tableName,
        string $columnName,
        string $status,
        int $progress = 0,
        string $error = null
    ): bool
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO encryption_migrations 
                (table_name, column_name, status, progress, error_message, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    progress = VALUES(progress),
                    error_message = VALUES(error_message),
                    updated_at = NOW()
            ");
            
            return $stmt->execute([
                $tableName,
                $columnName,
                $status,
                $progress,
                $error
            ]);
        } catch (PDOException $e) {
            error_log("Error logging migration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene estado actual de una migración
     * 
     * @param \PDO $db Conexión PDO
     * @param string $tableName Tabla
     * @param string $columnName Columna
     * @return array|null Estado actual o null si no existe
     */
    public static function getMigrationStatus(
        \PDO $db,
        string $tableName,
        string $columnName
    ): ?array
    {
        try {
            $stmt = $db->prepare("
                SELECT * FROM encryption_migrations 
                WHERE table_name = ? AND column_name = ?
            ");
            
            $stmt->execute([$tableName, $columnName]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting migration status: " . $e->getMessage());
            return null;
        }
    }
}
