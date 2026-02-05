<?php
/**
 * Comprueba y crea columnas faltantes en la tabla `patients`.
 * Uso: php ensure_patient_columns.php
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';

$columns = [
    // name => SQL definition
    'estado_civil' => "VARCHAR(100) NULL",
    'nombre_esposo' => "VARCHAR(255) NULL",
    'lugar_nacimiento' => "VARCHAR(255) NULL",
    'nacionalidad' => "VARCHAR(100) NULL",
    'dpi' => "VARCHAR(100) NULL",
    'tipo_sangre' => "VARCHAR(50) NULL",
    'profesion' => "VARCHAR(255) NULL",
    'lugar_trabajo' => "VARCHAR(255) NULL",
    'telefono_casa' => "VARCHAR(50) NULL",
    'celular' => "VARCHAR(50) NULL",
    'referido_por' => "VARCHAR(255) NULL",
    'motivo_consulta' => "TEXT NULL",
    'emitir_factura' => "VARCHAR(255) NULL",
    // nit is often added by migrations, ensure exists
    'nit' => "VARCHAR(100) NULL"
];

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    echo "ERROR: No se pudo conectar a la base de datos: " . $e->getMessage() . "\n";
    exit(1);
}

$added = [];
foreach ($columns as $col => $def) {
    try {
        $row = $db->fetchOne("SHOW COLUMNS FROM patients LIKE ?", [$col]);
        if ($row) {
            echo "Column $col already exists.\n";
            continue;
        }
    } catch (Exception $e) {
        // If table doesn't exist or SHOW COLUMNS fails, report and stop
        echo "ERROR checking column $col: " . $e->getMessage() . "\n";
        // try to continue
    }

    try {
        $sql = "ALTER TABLE patients ADD COLUMN $col $def";
        $db->execute($sql, []);
        echo "Added column: $col ($def)\n";
        $added[] = $col;
    } catch (Exception $e) {
        echo "Failed to add column $col: " . $e->getMessage() . "\n";
    }
}

if (count($added) === 0) {
    echo "No new columns added.\n";
} else {
    echo "Added columns: " . implode(', ', $added) . "\n";
}

exit(0);
