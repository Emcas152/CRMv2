-- Seeds MySQL (datos de prueba)
-- Requiere haber aplicado antes backend/docs/schema.mysql.sql

-- Usuarios (bcrypt)
-- superadmin@crm.com / superadmin123
-- admin@crm.com / admin123
-- doctor@crm.com / doctor123
-- staff@crm.com / staff123
-- patient@crm.com / patient123
INSERT INTO users (name, email, password, role, phone, active, email_verified)
VALUES
  ('Super Administrador', 'superadmin@crm.com', '$2y$10$/ZqfiXNHb5NSc/v.IYKgjutEHKkrF0XylUR7SSMJLEPkAWjCs3j4G', 'superadmin', '+502 0000-0000', 1, 1),
  ('Administrador del Sistema', 'admin@crm.com', '$2y$10$LCZ694UWcBZFGWU3n5KLy.B5u5pb92ZnQaKNEl9duL2xp5bh32UL2', 'admin', '+502 1234-5678', 1, 1),
  ('Dr. Carlos Méndez', 'doctor@crm.com', '$2y$10$EfLeZuHP6tRKNZ.6Aap4YOI34s7qmdqvspGuLEGGoolwAdN91zx7.', 'doctor', '+502 2345-6789', 1, 1),
  ('Ana López', 'staff@crm.com', '$2y$10$MkeSD/OqL.8fnTDAomVjzeejRy0GKQW7fRiCd7nR13/H0jW7gU5Hy', 'staff', '+502 3456-7890', 1, 1),
  ('María González', 'patient@crm.com', '$2y$10$qEmHCG7sXAA8VU5E.mN6du.Xl8CeS7AOs3iqDwQuCDQWjIC62TLii', 'patient', '+502 5551-1234', 1, 1)
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- Pacientes (5)
-- Nota: el paciente “patient@crm.com” está vinculado al user_id=5.
INSERT INTO patients (user_id, name, email, phone, birthday, address, nit, loyalty_points)
VALUES
  (5, 'María González', 'patient@crm.com', '+502 5551-1234', '1985-03-15', '3a Calle 5-20 Zona 10, Guatemala', NULL, 150),
  (NULL, 'Juan Pérez', 'juan.perez@email.com', '+502 5552-2345', '1990-07-22', '10a Avenida 12-45 Zona 1, Guatemala', NULL, 200),
  (NULL, 'Ana Martínez', 'ana.martinez@email.com', '+502 5553-3456', '1988-11-08', '15 Calle 8-30 Zona 14, Guatemala', NULL, 100),
  (NULL, 'Carlos Rodríguez', 'carlos.rodriguez@email.com', '+502 5554-4567', '1992-02-18', '5a Avenida 20-15 Zona 4, Guatemala', NULL, 75),
  (NULL, 'Sofía López', 'sofia.lopez@email.com', '+502 5555-5678', '1995-09-30', '8a Calle 15-40 Zona 11, Guatemala', NULL, 50)
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- Staff members
-- Nota: doctor user_id=3, staff user_id=4.
INSERT INTO staff_members (user_id, name, position, phone)
VALUES
  (3, 'Dr. Carlos Méndez', 'Cirujano Plástico', '+502 2345-6789'),
  (4, 'Ana López', 'Enfermera Especializada', '+502 3456-7890'),
  (NULL, 'Dra. Patricia Ruiz', 'Dermatóloga', '+502 4567-8901')
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- Productos y servicios (15)
INSERT INTO products (name, sku, description, price, stock, low_stock_alert, type, active)
VALUES
  ('Botox 50U', 'BOT-50', 'Toxina botulínica tipo A - 50 unidades para tratamiento de arrugas', 4500.00, 12, 10, 'product', 1),
  ('Botox 100U', 'BOT-100', 'Toxina botulínica tipo A - 100 unidades', 8000.00, 8, 10, 'product', 1),
  ('Ácido Hialurónico 1ml', 'AH-1ML', 'Relleno dérmico de ácido hialurónico - 1ml', 3800.00, 20, 10, 'product', 1),
  ('Ácido Hialurónico 2ml', 'AH-2ML', 'Relleno dérmico de ácido hialurónico - 2ml', 7000.00, 15, 10, 'product', 1),
  ('Peeling TCA 15%', 'PEE-TCA15', 'Solución de ácido tricloroacético al 15%', 800.00, 10, 10, 'product', 1),
  ('Sérum Vitamina C', 'SER-VITC', 'Sérum antioxidante con vitamina C al 20%', 450.00, 25, 10, 'product', 1),
  ('Crema Post-Tratamiento', 'CRE-POST', 'Crema reparadora post-procedimiento', 350.00, 30, 10, 'product', 1),

  ('Limpieza Facial Profunda', 'SRV-LFP', 'Tratamiento de limpieza facial profunda con extracción', 850.00, NULL, 0, 'service', 1),
  ('Hidrafacial', 'SRV-HF', 'Tratamiento de hidratación profunda HydraFacial', 1500.00, NULL, 0, 'service', 1),
  ('Peeling Químico', 'SRV-PQ', 'Peeling químico superficial o medio', 1200.00, NULL, 0, 'service', 1),

  ('Masaje Relajante', 'SRV-MR', 'Masaje relajante de cuerpo completo - 60 min', 600.00, NULL, 0, 'service', 1),
  ('Depilación Láser Facial', 'SRV-DLF', 'Sesión de depilación láser facial', 400.00, NULL, 0, 'service', 1),

  ('Láser Fraccionado CO2', 'SRV-LFC', 'Tratamiento con láser fraccionado CO2 para rejuvenecimiento', 2500.00, NULL, 0, 'service', 1),
  ('Radiofrecuencia Facial', 'SRV-RFF', 'Tratamiento de radiofrecuencia para tensado facial', 1800.00, NULL, 0, 'service', 1),
  ('Mesoterapia Facial', 'SRV-MF', 'Mesoterapia facial con vitaminas y nutrientes', 950.00, NULL, 0, 'service', 1)
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- Citas (8)
INSERT INTO appointments (patient_id, staff_member_id, appointment_date, appointment_time, service, status, notes)
VALUES
  (1, 1, CURDATE(), '09:00:00', 'Aplicación de Botox 50U', 'confirmed', 'Primera sesión de Botox en frente y entrecejo'),
  (2, 1, CURDATE(), '11:30:00', 'Consulta Ácido Hialurónico', 'confirmed', 'Evaluación para relleno de labios'),
  (3, 2, CURDATE(), '14:00:00', 'Limpieza Facial Profunda', 'pending', 'Cliente regular - limpieza mensual'),

  (4, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', 'Láser Fraccionado CO2', 'confirmed', 'Segunda sesión de rejuvenecimiento'),
  (5, 3, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '15:30:00', 'Peeling Químico', 'pending', 'Primera vez - explicar cuidados post'),
  (1, 2, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '09:30:00', 'Hidrafacial', 'confirmed', 'Mantenimiento mensual'),

  (2, 1, DATE_SUB(CURDATE(), INTERVAL 7 DAY), '10:00:00', 'Consulta inicial Botox', 'completed', 'Cliente satisfecho - programar seguimiento'),
  (3, 3, DATE_SUB(CURDATE(), INTERVAL 14 DAY), '16:00:00', 'Depilación Láser Facial', 'completed', 'Sesión 3 de 6 - buenos resultados');

-- Ventas (5)
INSERT INTO sales (patient_id, subtotal, discount, total, payment_method, status, notes, created_at)
VALUES
  (1, 4500.00, 450.00, 4050.00, 'card', 'completed', 'Pago con tarjeta - descuento cliente frecuente 10%', DATE_SUB(NOW(), INTERVAL 7 DAY)),
  (2, 3800.00, 0.00, 3800.00, 'cash', 'completed', 'Pago en efectivo', DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (3, 850.00, 0.00, 850.00, 'transfer', 'completed', 'Transferencia bancaria', DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (4, 5300.00, 300.00, 5000.00, 'card', 'completed', 'Botox + Limpieza facial - descuento paquete', DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (5, 1500.00, 0.00, 1500.00, 'cash', 'completed', 'Hidrafacial - primera visita', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- Items ventas
INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal)
VALUES
  (1, 1, 1, 4500.00, 4500.00),
  (2, 3, 1, 3800.00, 3800.00),
  (3, 8, 1, 850.00, 850.00),
  (4, 1, 1, 4500.00, 4500.00),
  (4, 8, 1, 800.00, 800.00),
  (5, 9, 1, 1500.00, 1500.00);

-- Plantillas de email por defecto
INSERT INTO email_templates (name, subject, body, variables, is_html)
VALUES
  ('welcome', 'Bienvenido a {{app_name}}', '<p>Hola {{name}},</p><p>Gracias por registrarte en {{app_name}}.</p><p>Tu cuenta ha sido creada exitosamente.</p>', '["name", "app_name"]', 1),
  ('appointment_reminder', 'Recordatorio de cita - {{date}}', '<p>Hola {{name}},</p><p>Te recordamos tu cita programada para el {{date}} a las {{time}}.</p><p>Servicio: {{service}}</p><p>Si necesitas reprogramar, contáctanos.</p>', '["name", "date", "time", "service"]', 1),
  ('sale_receipt', 'Comprobante de venta #{{sale_id}}', '<p>Hola {{name}},</p><p>Gracias por tu compra.</p><p>Total: {{total}}</p><p>Detalles: {{items}}</p>', '["name", "sale_id", "total", "items"]', 1)
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- =========================
-- CRM: Actualizaciones / Mensajes / Tareas / Comentarios
-- =========================

-- Actualizaciones (visibles para todos)
INSERT INTO updates (created_by, title, body, audience_type)
VALUES
  (2, 'Bienvenidos al CRM', 'Este es el módulo de comunicación interna. Puedes crear tareas, enviar mensajes y dejar comentarios.', 'all'),
  (3, 'Recordatorio', 'Por favor registrar notas completas en cada cita para mejorar el seguimiento.', 'role')
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

-- Nota: la fila 2 es audience_type=role pero requiere audience_role; se inserta aparte.
UPDATE updates SET audience_role = 'doctor' WHERE title = 'Recordatorio' AND audience_type = 'role' AND audience_role IS NULL;

-- Tareas
INSERT INTO tasks (created_by, assigned_to_user_id, related_patient_id, title, description, status, priority, due_date)
VALUES
  (3, 4, 1, 'Llamar a paciente por seguimiento', 'Confirmar evolución post-tratamiento y agendar control.', 'open', 'high', DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
  (2, 3, NULL, 'Revisar inventario de Botox', 'Verificar stock real y ajustar mínimos.', 'in_progress', 'normal', DATE_ADD(CURDATE(), INTERVAL 7 DAY));

-- Conversación doctor <-> paciente
INSERT INTO conversations (subject, created_by)
VALUES ('Consulta post tratamiento', 3);

SET @conv_id := LAST_INSERT_ID();

INSERT INTO conversation_participants (conversation_id, user_id, last_read_at)
VALUES
  (@conv_id, 3, NOW()),
  (@conv_id, 5, NULL);

INSERT INTO messages (conversation_id, sender_user_id, body)
VALUES
  (@conv_id, 3, 'Hola María, ¿cómo te sientes después del procedimiento?'),
  (@conv_id, 5, 'Hola doctor, me siento bien. Solo una leve sensibilidad.'),
  (@conv_id, 3, 'Perfecto. Si notas enrojecimiento fuerte o dolor, avísanos.');

-- Comentarios (en tareas)
INSERT INTO comments (entity_type, entity_id, author_user_id, body)
VALUES
  ('task', 1, 4, 'Ok, la llamaré hoy en la tarde.'),
  ('task', 1, 3, 'Gracias. Registra el resultado en el historial.'),
  ('task', 2, 2, 'Revisar también proveedores alternativos.');
