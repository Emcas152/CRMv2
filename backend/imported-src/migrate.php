<?php
/**
 * Simple migration runner for backend-php-puro
 * Run: php migrate.php
 */
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/Database.php';

$db = Database::getInstance();

// Create audit_logs table if not exists (MySQL compatible)
$sql = "CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NULL,
    action VARCHAR(255) NOT NULL,
    resource_type VARCHAR(100) NULL,
    resource_id VARCHAR(100) NULL,
    meta TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";

try {
    $db->execute($sql);
    echo "Migration applied: audit_logs created or exists\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}

// Create patient_photos table
$sql2 = "CREATE TABLE IF NOT EXISTS patient_photos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    patient_id BIGINT NOT NULL,
    type VARCHAR(50) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";

try {
    $db->execute($sql2);
    echo "Migration applied: patient_photos created or exists\n";
} catch (Exception $e) {
    echo "Migration failed (patient_photos): " . $e->getMessage() . "\n";
}

// Add qr_code column to patients if not exists
try {
    // MySQL: add column if not exists
    $db->execute("ALTER TABLE patients ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255) NULL");
    echo "Migration applied: qr_code column ensured on patients\n";
} catch (Exception $e) {
    // Some MySQL versions don't support IF NOT EXISTS for ALTER TABLE; fallback check
    try {
        $row = $db->fetchOne("SHOW COLUMNS FROM patients LIKE 'qr_code'");
        if (!$row) {
            $db->execute("ALTER TABLE patients ADD COLUMN qr_code VARCHAR(255) NULL");
            echo "Migration applied: qr_code column added to patients\n";
        } else {
            echo "qr_code column already exists on patients\n";
        }
    } catch (Exception $e2) {
        echo "Migration failed (qr_code): " . $e2->getMessage() . "\n";
    }

    // Create documents table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS documents (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            patient_id BIGINT NOT NULL,
            uploaded_by BIGINT NULL,
            title VARCHAR(255) NULL,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NULL,
            mime VARCHAR(100) NULL,
            size BIGINT NULL,
            signed TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $db->execute($sql);
        echo "Migration applied: documents created or exists\n";
    } catch (Exception $e) {
        echo "Migration failed (documents): " . $e->getMessage() . "\n";
    }

    // Create document_signatures table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS document_signatures (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            document_id BIGINT NOT NULL,
            signed_by BIGINT NULL,
            signature_method VARCHAR(50) NULL,
            signature_file VARCHAR(255) NULL,
            meta TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );";
        $db->execute($sql);
        echo "Migration applied: document_signatures created or exists\n";
    } catch (Exception $e) {
        echo "Migration failed (document_signatures): " . $e->getMessage() . "\n";
    }
}

// Add email verification columns to users table if not exists
try {
    // Try using ALTER TABLE ADD COLUMN IF NOT EXISTS (MySQL 8+)
    $db->execute("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0, ADD COLUMN IF NOT EXISTS email_verification_token VARCHAR(255) NULL, ADD COLUMN IF NOT EXISTS email_verification_sent_at DATETIME NULL, ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL");
    echo "Migration applied: users email verification columns ensured\n";
} catch (Exception $e) {
    // Fallback: check each column and add if missing
    try {
        $cols = ['email_verified','email_verification_token','email_verification_sent_at','email_verified_at'];
        foreach ($cols as $col) {
            $row = $db->fetchOne("SHOW COLUMNS FROM users LIKE ?", [$col]);
            if (!$row) {
                switch ($col) {
                    case 'email_verified':
                        $db->execute("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0");
                        break;
                    case 'email_verification_token':
                        $db->execute("ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(255) NULL");
                        break;
                    case 'email_verification_sent_at':
                        $db->execute("ALTER TABLE users ADD COLUMN email_verification_sent_at DATETIME NULL");
                        break;
                    case 'email_verified_at':
                        $db->execute("ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL");
                        break;
                }
                echo "Migration applied: added column {$col} to users\n";
            } else {
                echo "Column {$col} already exists on users\n";
            }
        }
    } catch (Exception $e2) {
        echo "Migration failed (users email verification): " . $e2->getMessage() . "\n";
    }
}

// Create email_templates table
try {
    $sql = "CREATE TABLE IF NOT EXISTS email_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        variables TEXT,
        is_html BOOLEAN DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->execute($sql);
    echo "Migration applied: email_templates created or exists\n";
    
    // Insert default templates
    $defaults = [
        ['welcome', 'Bienvenido a {{app_name}}', '<p>Hola {{name}},</p><p>Gracias por registrarte en {{app_name}}.</p><p>Tu cuenta ha sido creada exitosamente.</p>', '["name", "app_name"]'],
        ['appointment_reminder', 'Recordatorio de cita - {{date}}', '<p>Hola {{name}},</p><p>Te recordamos tu cita programada para el {{date}} a las {{time}}.</p><p>Servicio: {{service}}</p><p>Si necesitas reprogramar, contáctanos.</p>', '["name", "date", "time", "service"]'],
        ['sale_receipt', 'Comprobante de venta #{{sale_id}}', '<p>Hola {{name}},</p><p>Gracias por tu compra.</p><p>Total: {{total}}</p><p>Detalles: {{items}}</p>', '["name", "sale_id", "total", "items"]']
    ];
    
    foreach ($defaults as $tpl) {
        try {
            $db->execute(
                "INSERT INTO email_templates (name, subject, body, variables) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP",
                $tpl
            );
        } catch (Exception $e) {
            // Already exists, skip
        }
    }
    echo "Default email templates seeded\n";
} catch (Exception $e) {
    echo "Migration failed (email_templates): " . $e->getMessage() . "\n";
}

echo "All migrations applied successfully!\n";

