-- =====================================================================
-- Migración: cuentas de administración y de prueba, rol Coordinador por zonas.
-- Aplicar DESPUÉS de 2026-11-24_avisos_imagen.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Tipo de cuenta: geca (llega con la carga), administracion (sin contraseña de
-- admin local) o prueba (no aparece en reportes).
ALTER TABLE usuarios
    ADD COLUMN tipo_cuenta ENUM('geca', 'administracion', 'prueba') NOT NULL DEFAULT 'geca' AFTER rol;
