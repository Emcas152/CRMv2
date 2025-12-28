-- Script de Instalación de Base de Datos
-- CRM Spa Médico - PHP Puro

-- Usar base de datos
USE crm_spa_medico;

-- Desactivar verificación de claves foráneas temporalmente
SET FOREIGN_KEY_CHECKS = 0;

-- Eliminar tablas existentes en el orden correcto
DROP TABLE IF EXISTS patient_photos;
DROP TABLE IF EXISTS patient_documents;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS staff_members;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS users;

-- Reactivar verificación de claves foráneas
SET FOREIGN_KEY_CHECKS = 1;

-- Tabla de usuarios
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin', 'admin', 'doctor', 'staff', 'patient') DEFAULT 'patient',
    phone VARCHAR(20),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de pacientes
CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(20),
    birthday DATE,
    address TEXT,
    qr_code VARCHAR(255),
    loyalty_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de productos
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100),
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT DEFAULT 0,
    low_stock_alert INT DEFAULT 10,
    type ENUM('product', 'service') DEFAULT 'product',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku (sku),
    INDEX idx_type (type),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de staff members
CREATE TABLE IF NOT EXISTS staff_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(255) NOT NULL,
    position VARCHAR(255),
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de citas
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    staff_member_id INT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    service VARCHAR(255) NOT NULL,
    status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE SET NULL,
    INDEX idx_patient_id (patient_id),
    INDEX idx_staff_member_id (staff_member_id),
    INDEX idx_date (appointment_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de ventas
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash',
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'completed',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    INDEX idx_patient_id (patient_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de items de venta
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_sale_id (sale_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de documentos de pacientes
CREATE TABLE IF NOT EXISTS patient_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(500) NOT NULL,
    type ENUM('consent', 'contract', 'prescription', 'lab_result', 'other') DEFAULT 'other',
    requires_signature BOOLEAN DEFAULT FALSE,
    signed_at TIMESTAMP NULL,
    signature_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    INDEX idx_patient_id (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de fotos de pacientes
CREATE TABLE IF NOT EXISTS patient_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    path VARCHAR(500) NOT NULL,
    type ENUM('before', 'after', 'other') DEFAULT 'other',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    INDEX idx_patient_id (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DATOS DE PRUEBA
-- ============================================

-- Usuarios del sistema
-- Contraseñas hasheadas con bcrypt (cost 10)
INSERT INTO users (name, email, password, role, phone, active) VALUES
-- superadmin@crm.com / superadmin123
('Super Administrador', 'superadmin@crm.com', '$2y$10$w7QwQwQwQwQwQwQwQwQwQeQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQwQw', 'superadmin', '+502 0000-0000', 1),
-- admin@crm.com / admin123
('Administrador del Sistema', 'admin@crm.com', '$2y$10$LCZ694UWcBZFGWU3n5KLy.B5u5pb92ZnQaKNEl9duL2xp5bh32UL2', 'admin', '+502 1234-5678', 1),
-- doctor@crm.com / doctor123
('Dr. Carlos Méndez', 'doctor@crm.com', '$2y$10$EfLeZuHP6tRKNZ.6Aap4YOI34s7qmdqvspGuLEGGoolwAdN91zx7.', 'doctor', '+502 2345-6789', 1),
-- staff@crm.com / staff123
('Ana López', 'staff@crm.com', '$2y$10$MkeSD/OqL.8fnTDAomVjzeejRy0GKQW7fRiCd7nR13/H0jW7gU5Hy', 'staff', '+502 3456-7890', 1),
-- patient@crm.com / patient123
('María González', 'patient@crm.com', '$2y$10$qEmHCG7sXAA8VU5E.mN6du.Xl8CeS7AOs3iqDwQuCDQWjIC62TLii', 'patient', '+502 5551-1234', 1);

-- Pacientes (5 pacientes de ejemplo)   
INSERT INTO patients (user_id, name, email, phone, birthday, address, loyalty_points) VALUES
(4, 'María González', 'patient@crm.com', '+502 5551-1234', '1985-03-15', '3a Calle 5-20 Zona 10, Guatemala', 150),
(NULL, 'Juan Pérez', 'juan.perez@email.com', '+502 5552-2345', '1990-07-22', '10a Avenida 12-45 Zona 1, Guatemala', 200),
(NULL, 'Ana Martínez', 'ana.martinez@email.com', '+502 5553-3456', '1988-11-08', '15 Calle 8-30 Zona 14, Guatemala', 100),
(NULL, 'Carlos Rodríguez', 'carlos.rodriguez@email.com', '+502 5554-4567', '1992-02-18', '5a Avenida 20-15 Zona 4, Guatemala', 75),
(NULL, 'Sofía López', 'sofia.lopez@email.com', '+502 5555-5678', '1995-09-30', '8a Calle 15-40 Zona 11, Guatemala', 50);

-- Staff members (personal médico)
INSERT INTO staff_members (user_id, name, position, phone) VALUES
(2, 'Dr. Carlos Méndez', 'Cirujano Plástico', '+502 2345-6789'),
(3, 'Ana López', 'Enfermera Especializada', '+502 3456-7890'),
(NULL, 'Dra. Patricia Ruiz', 'Dermatóloga', '+502 4567-8901');

-- Productos y servicios (15 items variados)
INSERT INTO products (name, sku, description, price, stock, type, active) VALUES
-- Productos inyectables
('Botox 50U', 'BOT-50', 'Toxina botulínica tipo A - 50 unidades para tratamiento de arrugas', 4500.00, 12, 'product', 1),
('Botox 100U', 'BOT-100', 'Toxina botulínica tipo A - 100 unidades', 8000.00, 8, 'product', 1),
('Ácido Hialurónico 1ml', 'AH-1ML', 'Relleno dérmico de ácido hialurónico - 1ml', 3800.00, 20, 'product', 1),
('Ácido Hialurónico 2ml', 'AH-2ML', 'Relleno dérmico de ácido hialurónico - 2ml', 7000.00, 15, 'product', 1),

-- Productos para tratamientos
('Peeling TCA 15%', 'PEE-TCA15', 'Solución de ácido tricloroacético al 15%', 800.00, 10, 'product', 1),
('Sérum Vitamina C', 'SER-VITC', 'Sérum antioxidante con vitamina C al 20%', 450.00, 25, 'product', 1),
('Crema Post-Tratamiento', 'CRE-POST', 'Crema reparadora post-procedimiento', 350.00, 30, 'product', 1),

-- Servicios faciales
('Limpieza Facial Profunda', 'SRV-LFP', 'Tratamiento de limpieza facial profunda con extracción', 850.00, NULL, 'service', 1),
('Hidrafacial', 'SRV-HF', 'Tratamiento de hidratación profunda HydraFacial', 1500.00, NULL, 'service', 1),
('Peeling Químico', 'SRV-PQ', 'Peeling químico superficial o medio', 1200.00, NULL, 'service', 1),

-- Servicios corporales
('Masaje Relajante', 'SRV-MR', 'Masaje relajante de cuerpo completo - 60 min', 600.00, NULL, 'service', 1),
('Depilación Láser Facial', 'SRV-DLF', 'Sesión de depilación láser facial', 400.00, NULL, 'service', 1),

-- Servicios avanzados
('Láser Fraccionado CO2', 'SRV-LFC', 'Tratamiento con láser fraccionado CO2 para rejuvenecimiento', 2500.00, NULL, 'service', 1),
('Radiofrecuencia Facial', 'SRV-RFF', 'Tratamiento de radiofrecuencia para tensado facial', 1800.00, NULL, 'service', 1),
('Mesoterapia Facial', 'SRV-MF', 'Mesoterapia facial con vitaminas y nutrientes', 950.00, NULL, 'service', 1);

-- Citas programadas (8 citas de ejemplo en diferentes estados)
INSERT INTO appointments (patient_id, staff_member_id, appointment_date, appointment_time, service, status, notes) VALUES
-- Citas de hoy
(1, 1, CURDATE(), '09:00:00', 'Aplicación de Botox 50U', 'confirmed', 'Primera sesión de Botox en frente y entrecejo'),
(2, 1, CURDATE(), '11:30:00', 'Consulta Ácido Hialurónico', 'confirmed', 'Evaluación para relleno de labios'),
(3, 2, CURDATE(), '14:00:00', 'Limpieza Facial Profunda', 'pending', 'Cliente regular - limpieza mensual'),

-- Citas futuras
(4, 1, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', 'Láser Fraccionado CO2', 'confirmed', 'Segunda sesión de rejuvenecimiento'),
(5, 3, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '15:30:00', 'Peeling Químico', 'pending', 'Primera vez - explicar cuidados post'),
(1, 2, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '09:30:00', 'Hidrafacial', 'confirmed', 'Mantenimiento mensual'),

-- Citas pasadas
(2, 1, DATE_SUB(CURDATE(), INTERVAL 7 DAY), '10:00:00', 'Consulta inicial Botox', 'completed', 'Cliente satisfecho - programar seguimiento'),
(3, 3, DATE_SUB(CURDATE(), INTERVAL 14 DAY), '16:00:00', 'Depilación Láser Facial', 'completed', 'Sesión 3 de 6 - buenos resultados');

-- Ventas (5 ventas de ejemplo)
INSERT INTO sales (patient_id, subtotal, discount, total, payment_method, status, notes, created_at) VALUES
(1, 4500.00, 450.00, 4050.00, 'card', 'completed', 'Pago con tarjeta - descuento cliente frecuente 10%', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(2, 3800.00, 0.00, 3800.00, 'cash', 'completed', 'Pago en efectivo', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(3, 850.00, 0.00, 850.00, 'transfer', 'completed', 'Transferencia bancaria', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(4, 5300.00, 300.00, 5000.00, 'card', 'completed', 'Botox + Limpieza facial - descuento paquete', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(5, 1500.00, 0.00, 1500.00, 'cash', 'completed', 'Hidrafacial - primera visita', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- Items de ventas (detalle de las ventas anteriores)
INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal) VALUES
-- Venta 1: Botox
(1, 1, 1, 4500.00, 4500.00),
-- Venta 2: Ácido Hialurónico
(2, 3, 1, 3800.00, 3800.00),
-- Venta 3: Limpieza Facial
(3, 8, 1, 850.00, 850.00),
-- Venta 4: Botox + Limpieza
(4, 1, 1, 4500.00, 4500.00),
(4, 8, 1, 800.00, 800.00),
-- Venta 5: Hidrafacial
(5, 9, 1, 1500.00, 1500.00);

-- Commit
COMMIT;

-- ============================================
-- MENSAJES FINALES
-- ============================================
SELECT '✅ Base de datos reiniciada exitosamente!' as RESULTADO;
SELECT '' as '';
SELECT '👥 CREDENCIALES DE ACCESO:' as INFO;
SELECT '' as '';
SELECT 'Superadmin: superadmin@crm.com / superadmin123' as CREDENCIALES;
SELECT 'Admin: admin@crm.com / admin123' as CREDENCIALES;
SELECT 'Doctor: doctor@crm.com / doctor123' as CREDENCIALES;
SELECT 'Personal: staff@crm.com / staff123' as CREDENCIALES;
SELECT 'Paciente: patient@crm.com / patient123' as CREDENCIALES;
SELECT '' as '';
SELECT '📊 DATOS CREADOS:' as INFO;
SELECT '• 4 usuarios del sistema' as DETALLE;
SELECT '• 5 pacientes' as DETALLE;
SELECT '• 3 miembros del personal' as DETALLE;
SELECT '• 15 productos y servicios' as DETALLE;
SELECT '• 8 citas (pasadas, hoy y futuras)' as DETALLE;
SELECT '• 5 ventas con items' as DETALLE;
