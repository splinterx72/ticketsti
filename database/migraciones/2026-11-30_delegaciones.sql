-- =====================================================================
-- Migración: delegación de tickets (el jefe autoriza a su secretaria u otras
-- personas para levantar tickets a su nombre).
-- Aplicar DESPUÉS de 2026-11-29_modulos_correo.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Delegación: el jefe autoriza a una o varias personas (p. ej. su secretaria)
-- para levantar tickets a su nombre. ver_todos = también ve y comenta los demás
-- tickets del jefe. Si el jefe no ha activado su cuenta, la registra TI.
CREATE TABLE delegaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jefe_id INT UNSIGNED NOT NULL,
    delegado_id INT UNSIGNED NOT NULL,
    ver_todos TINYINT(1) NOT NULL DEFAULT 1,
    hasta DATE NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_delegacion (jefe_id, delegado_id),
    INDEX idx_deleg_delegado (delegado_id),
    CONSTRAINT fk_deleg_jefe FOREIGN KEY (jefe_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_delegado FOREIGN KEY (delegado_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_creado FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién levantó realmente el ticket cuando lo hizo a nombre de otra persona.
ALTER TABLE tickets
    ADD COLUMN creado_por_id INT UNSIGNED NULL AFTER usuario_solicitante_id,
    ADD CONSTRAINT fk_ticket_creado_por FOREIGN KEY (creado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL;
