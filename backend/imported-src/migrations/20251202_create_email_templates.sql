-- Migration: create email_templates table
CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    variables TEXT, -- JSON array of variable names like ["name", "date", "amount"]
    is_html BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default templates
INSERT OR IGNORE INTO email_templates (name, subject, body, variables, is_html) VALUES
('welcome', 'Bienvenido a {{app_name}}', '<p>Hola {{name}},</p><p>Gracias por registrarte en {{app_name}}.</p><p>Tu cuenta ha sido creada exitosamente.</p>', '["name", "app_name"]', 1),
('appointment_reminder', 'Recordatorio de cita - {{date}}', '<p>Hola {{name}},</p><p>Te recordamos tu cita programada para el {{date}} a las {{time}}.</p><p>Servicio: {{service}}</p><p>Si necesitas reprogramar, contáctanos.</p>', '["name", "date", "time", "service"]', 1),
('sale_receipt', 'Comprobante de venta #{{sale_id}}', '<p>Hola {{name}},</p><p>Gracias por tu compra.</p><p>Total: {{total}}</p><p>Detalles: {{items}}</p>', '["name", "sale_id", "total", "items"]', 1);
