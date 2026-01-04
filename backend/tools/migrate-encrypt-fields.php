<?php
/**
 * Script de Migración: Encriptación de Campos Sensibles
 * 
 * Encripta datos existentes en:
 * - products.price → products.price_encrypted + hash
 * - patients.email → patients.email_encrypted + email_hash
 * - patients.phone → patients.phone_encrypted + phone_hash
 * - users.phone → users.phone_encrypted + phone_hash
 * 
 * Uso:
 *   php migrate-encrypt-fields.php
 */

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/FieldEncryption.php';

use App\Core\Database;
use App\Core\FieldEncryption;

// Configuración
define('BATCH_SIZE', 100); // Procesar 100 registros por lote
define('VERBOSE', true); // Mostrar progress

class EncryptionMigration
{
    private $db;
    private $migrations = [
        [
            'table' => 'products',
            'column' => 'price',
            'encrypted_column' => 'price_encrypted',
            'type' => FieldEncryption::TYPE_PRICE,
            'has_hash' => false
        ],
        [
            'table' => 'patients',
            'column' => 'email',
            'encrypted_column' => 'email_encrypted',
            'hash_column' => 'email_hash',
            'type' => FieldEncryption::TYPE_EMAIL,
            'has_hash' => true
        ],
        [
            'table' => 'patients',
            'column' => 'phone',
            'encrypted_column' => 'phone_encrypted',
            'hash_column' => 'phone_hash',
            'type' => FieldEncryption::TYPE_PHONE,
            'has_hash' => true
        ],
        [
            'table' => 'users',
            'column' => 'phone',
            'encrypted_column' => 'phone_encrypted',
            'hash_column' => 'phone_hash',
            'type' => FieldEncryption::TYPE_PHONE,
            'has_hash' => true
        ]
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Ejecuta todas las migraciones
     */
    public function runAll(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║       MIGRATION: Encryptación de Campos Sensibles             ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        
        foreach ($this->migrations as $migration) {
            $this->migrateSingleField($migration);
        }
        
        $this->showFinalStatus();
        echo "\n✅ Migración completada\n\n";
    }
    
    /**
     * Migra un campo individual
     */
    private function migrateSingleField(array $migration): void
    {
        $table = $migration['table'];
        $column = $migration['column'];
        $encrypted_column = $migration['encrypted_column'];
        $hash_column = $migration['hash_column'] ?? null;
        
        $this->log("\n→ Migrando {$table}.{$column}");
        
        // Marcar como iniciado
        FieldEncryption::logMigration(
            $this->db,
            $table,
            $column,
            'in_progress',
            0
        );
        
        try {
            // Contar registros sin encriptar
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM {$table} WHERE {$encrypted_column} IS NULL AND {$column} IS NOT NULL");
            $stmt->execute();
            $total = $stmt->fetch(\PDO::FETCH_ASSOC)['count'];
            
            if ($total === 0) {
                $this->log("  ✓ Ya encriptado (0 registros pendientes)");
                FieldEncryption::logMigration(
                    $this->db,
                    $table,
                    $column,
                    'completed',
                    100
                );
                return;
            }
            
            $this->log("  Procesando {$total} registros...");
            
            // Procesar por lotes
            $processed = 0;
            $failed = 0;
            
            while ($processed < $total) {
                // Obtener siguiente lote
                $sql = "SELECT id, {$column} FROM {$table} WHERE {$encrypted_column} IS NULL AND {$column} IS NOT NULL LIMIT " . BATCH_SIZE;
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $batch = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                if (empty($batch)) break;
                
                // Encriptar cada registro del lote
                foreach ($batch as $row) {
                    try {
                        $id = $row['id'];
                        $value = $row[$column];
                        
                        // Encriptar
                        $encrypted = FieldEncryption::encryptValue($value);
                        
                        if ($hash_column) {
                            $hash = FieldEncryption::hashValue($value);
                            $update_sql = "UPDATE {$table} SET {$encrypted_column} = ?, {$hash_column} = ? WHERE id = ?";
                            $update_stmt = $this->db->prepare($update_sql);
                            $update_stmt->execute([$encrypted, $hash, $id]);
                        } else {
                            $update_sql = "UPDATE {$table} SET {$encrypted_column} = ? WHERE id = ?";
                            $update_stmt = $this->db->prepare($update_sql);
                            $update_stmt->execute([$encrypted, $id]);
                        }
                        
                        $processed++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log("  ✗ Error en id {$id}: " . $e->getMessage(), true);
                    }
                }
                
                // Mostrar progreso
                $percentage = (int)(($processed / $total) * 100);
                $this->log("  ▓ Progreso: {$processed}/{$total} ({$percentage}%)", false, true);
                
                // Actualizar tracking
                FieldEncryption::logMigration(
                    $this->db,
                    $table,
                    $column,
                    'in_progress',
                    $percentage
                );
            }
            
            // Marcar como completado
            if ($failed === 0) {
                FieldEncryption::logMigration(
                    $this->db,
                    $table,
                    $column,
                    'completed',
                    100
                );
                $this->log("  ✅ Completado: {$processed} registros encriptados");
            } else {
                FieldEncryption::logMigration(
                    $this->db,
                    $table,
                    $column,
                    'failed',
                    100,
                    "{$failed} errores en migración"
                );
                $this->log("  ⚠️  Completado con errores: {$processed} éxito, {$failed} fallos");
            }
        } catch (\Exception $e) {
            FieldEncryption::logMigration(
                $this->db,
                $table,
                $column,
                'failed',
                0,
                $e->getMessage()
            );
            $this->log("  ❌ Error: " . $e->getMessage(), true);
        }
    }
    
    /**
     * Muestra estado final
     */
    private function showFinalStatus(): void
    {
        echo "\n╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                     Estado Final                              ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n";
        
        try {
            $stmt = $this->db->prepare("SELECT * FROM v_encryption_status");
            $stmt->execute();
            $status = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($status as $row) {
                $icon = $row['icon'] ?? '?';
                $table = str_pad($row['table_name'], 15);
                $column = str_pad($row['column_name'], 15);
                $progress = str_pad($row['progress'], 8);
                echo "{$icon} {$table} | {$column} | {$progress} | {$row['status']}\n";
            }
        } catch (\Exception $e) {
            $this->log("Error al mostrar estado: " . $e->getMessage(), true);
        }
    }
    
    /**
     * Log con carriage return para sobrescribir línea
     */
    private function log(string $message, bool $error = false, bool $overwrite = false): void
    {
        if (!VERBOSE) return;
        
        if ($overwrite) {
            echo "\r" . str_pad($message, 70);
        } else {
            echo $message . "\n";
        }
        
        if ($error) {
            error_log($message);
        }
    }
}

// Ejecutar si se llama desde CLI
if (php_sapi_name() === 'cli') {
    try {
        $migration = new EncryptionMigration();
        $migration->runAll();
    } catch (\Exception $e) {
        echo "❌ Error fatal: " . $e->getMessage() . "\n";
        exit(1);
    }
}
