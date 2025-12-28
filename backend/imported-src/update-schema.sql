-- Script para actualizar la estructura de la base de datos existente
-- Ejecutar SOLO si ya tienes las tablas creadas

-- Agregar columnas faltantes en la tabla sales
ALTER TABLE sales 
ADD COLUMN IF NOT EXISTS subtotal DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER patient_id,
ADD COLUMN IF NOT EXISTS discount DECIMAL(10,2) DEFAULT 0 AFTER subtotal,
ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'card', 'transfer', 'other') DEFAULT 'cash' AFTER total,
ADD COLUMN IF NOT EXISTS notes TEXT AFTER payment_method;

-- Agregar índice para status en sales si no existe
ALTER TABLE sales ADD INDEX IF NOT EXISTS idx_status (status);

-- Actualizar enum de status en appointments (agregar 'pending' si solo existe 'scheduled')
ALTER TABLE appointments MODIFY COLUMN status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'scheduled') DEFAULT 'pending';

-- Mensaje
SELECT 'Estructura de base de datos actualizada!' as mensaje;
