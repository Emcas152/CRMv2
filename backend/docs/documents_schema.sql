-- Schema mínimo para habilitar el módulo /api/v1/documents
-- Compatible con app/Controllers/DocumentsController.php

-- Crea tabla principal de documentos
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    uploaded_by INT NULL,
    title VARCHAR(255) NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(190) NULL,
    size BIGINT NULL,
    signed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_patient_id
        FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_documents_patient_id (patient_id),
    INDEX idx_documents_uploaded_by (uploaded_by),
    INDEX idx_documents_created_at (created_at),
    INDEX idx_documents_signed (signed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crea tabla de firmas / auditoría de firma
CREATE TABLE IF NOT EXISTS document_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    signed_by INT NULL,
    signature_method VARCHAR(50) NOT NULL DEFAULT 'manual',
    signature_file VARCHAR(255) NULL,
    meta LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_document_signatures_document_id
        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_document_signatures_signed_by
        FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_signatures_document_id (document_id),
    INDEX idx_document_signatures_signed_by (signed_by),
    INDEX idx_document_signatures_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
