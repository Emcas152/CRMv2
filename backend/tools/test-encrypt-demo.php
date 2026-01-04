<?php
/**
 * DEMO: Simulación de Migración de Encriptación
 * 
 * Este script demuestra el flujo de encriptación sin requerir BD real
 * Útil para testing y validación
 */

require_once __DIR__ . '/../app/Core/Crypto.php';
require_once __DIR__ . '/../app/Core/FieldEncryption.php';

use App\Core\FieldEncryption;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     DEMO: Simulación de Migración de Encriptación             ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Datos de ejemplo (simulan registros de BD)
$testData = [
    'products' => [
        ['id' => 1, 'name' => 'Producto A', 'price' => '99.99'],
        ['id' => 2, 'name' => 'Producto B', 'price' => '149.50'],
        ['id' => 3, 'name' => 'Producto C', 'price' => '49.99'],
    ],
    'patients' => [
        ['id' => 1, 'name' => 'Juan Pérez', 'email' => 'juan@example.com', 'phone' => '+34612345678'],
        ['id' => 2, 'name' => 'María López', 'email' => 'maria@example.com', 'phone' => '+34687654321'],
        ['id' => 3, 'name' => 'Carlos Gómez', 'email' => 'carlos@example.com', 'phone' => '+34698765432'],
    ],
    'users' => [
        ['id' => 1, 'name' => 'Admin', 'email' => 'admin@app.com', 'phone' => '+34612111111'],
        ['id' => 2, 'name' => 'Doctor', 'email' => 'doctor@app.com', 'phone' => '+34612222222'],
    ]
];

// Configurar tipos de encriptación
$encryptionConfig = [
    'products' => [
        'price' => [
            'type' => FieldEncryption::TYPE_PRICE,
            'encrypted_column' => 'price_encrypted',
            'has_hash' => false
        ]
    ],
    'patients' => [
        'email' => [
            'type' => FieldEncryption::TYPE_EMAIL,
            'encrypted_column' => 'email_encrypted',
            'hash_column' => 'email_hash',
            'has_hash' => true
        ],
        'phone' => [
            'type' => FieldEncryption::TYPE_PHONE,
            'encrypted_column' => 'phone_encrypted',
            'hash_column' => 'phone_hash',
            'has_hash' => true
        ]
    ],
    'users' => [
        'phone' => [
            'type' => FieldEncryption::TYPE_PHONE,
            'encrypted_column' => 'phone_encrypted',
            'hash_column' => 'phone_hash',
            'has_hash' => true
        ]
    ]
];

// Array para resultados
$results = [];
$totalProcessed = 0;
$totalErrors = 0;

// Procesar cada tabla
foreach ($encryptionConfig as $table => $columns) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📊 Tabla: $table\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $tableRecords = $testData[$table] ?? [];
    
    foreach ($columns as $column => $config) {
        echo "  🔐 Columna: $column\n";
        echo "     Tipo: " . basename($config['type']) . "\n";
        
        $processed = 0;
        $errors = 0;
        $encryptedRecords = [];
        
        foreach ($tableRecords as $record) {
            if (!isset($record[$column]) || empty($record[$column])) {
                continue;
            }
            
            $value = $record[$column];
            
            try {
                // Validar formato
                if (!FieldEncryption::validateValue($value, $config['type'])) {
                    throw new Exception("Validación fallida: '$value'");
                }
                
                // Encriptar
                $encrypted = FieldEncryption::encryptValue($value);
                $hash = $config['has_hash'] ? FieldEncryption::hashValue($value) : null;
                
                $encryptedRecords[] = [
                    'id' => $record['id'],
                    'original' => $value,
                    'encrypted' => substr($encrypted, 0, 50) . '...',
                    'encrypted_length' => strlen($encrypted),
                    'hash' => $hash ? substr($hash, 0, 32) . '...' : 'N/A',
                ];
                
                $processed++;
                $totalProcessed++;
                
            } catch (Exception $e) {
                echo "     ❌ Error en ID {$record['id']}: " . $e->getMessage() . "\n";
                $errors++;
                $totalErrors++;
            }
        }
        
        echo "     ✅ Procesados: $processed\n";
        if ($errors > 0) {
            echo "     ⚠️  Errores: $errors\n";
        }
        
        // Mostrar primeros registros encriptados
        if (!empty($encryptedRecords)) {
            echo "\n     📋 Primeros registros encriptados:\n";
            foreach (array_slice($encryptedRecords, 0, 2) as $rec) {
                echo "        ID {$rec['id']}: {$rec['original']}\n";
                echo "        ├─ Encrypted: {$rec['encrypted']} ({$rec['encrypted_length']} bytes)\n";
                echo "        └─ Hash: {$rec['hash']}\n";
            }
        }
        
        echo "\n";
        
        $results[] = [
            'table' => $table,
            'column' => $column,
            'processed' => $processed,
            'errors' => $errors,
            'status' => $errors === 0 ? 'completed' : 'failed'
        ];
    }
}

// Resumen final
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    RESUMEN DE MIGRACIÓN                        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "┌─ TABLA DE RESULTADOS ─────────────────────────────────────────┐\n";
printf("│ %-15s │ %-12s │ %9s │ %8s │ %10s │\n", "Tabla", "Columna", "Procesados", "Errores", "Status");
echo "├─────────────────┼──────────────┼───────────┼──────────┼────────────┤\n";

foreach ($results as $result) {
    $status = $result['status'] === 'completed' ? '✅ OK' : '❌ FAILED';
    printf("│ %-15s │ %-12s │ %9d │ %8d │ %10s │\n", 
        $result['table'], 
        $result['column'], 
        $result['processed'], 
        $result['errors'], 
        $status
    );
}

echo "└─────────────────┴──────────────┴───────────┴──────────┴────────────┘\n\n";

// Estadísticas
echo "📈 ESTADÍSTICAS GLOBALES:\n";
echo "   • Total procesados: $totalProcessed\n";
echo "   • Total errores: $totalErrors\n";
echo "   • Tasa éxito: " . ($totalProcessed > 0 ? round(($totalProcessed - $totalErrors) / $totalProcessed * 100, 1) . '%' : 'N/A') . "\n\n";

// Test de roundtrip (encriptar y desencriptar)
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🧪 TEST: Encriptación/Desencriptación Roundtrip\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$testValues = [
    'email' => ['example@email.com', FieldEncryption::TYPE_EMAIL],
    'phone' => ['+34612345678', FieldEncryption::TYPE_PHONE],
    'price' => ['99.99', FieldEncryption::TYPE_PRICE],
];

foreach ($testValues as $type => [$value, $typeConst]) {
    try {
        $encrypted = FieldEncryption::encryptValue($value);
        $decrypted = FieldEncryption::decryptValue($encrypted);
        $match = $value === $decrypted ? '✅' : '❌';
        
        echo "$match $type:\n";
        echo "   Original:   $value\n";
        echo "   Encrypted:  " . substr($encrypted, 0, 40) . "...\n";
        echo "   Decrypted:  $decrypted\n";
        echo "   Match: " . ($value === $decrypted ? 'YES' : 'NO') . "\n\n";
    } catch (Exception $e) {
        echo "❌ $type: " . $e->getMessage() . "\n\n";
    }
}

echo "\n✨ Demo completada exitosamente\n";
echo "   Cuando la BD esté disponible, ejecuta:\n";
echo "   php backend/tools/migrate-encrypt-fields.php\n\n";
