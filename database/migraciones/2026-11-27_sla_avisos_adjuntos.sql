-- =====================================================================
-- Migración: acuse de lectura de avisos, tiempos de atención (SLA) y
-- adjuntos en tickets. Aplicar DESPUÉS de 2026-11-24_coordinadores_cuentas.sql,
-- UNA sola vez, desde phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Avisos: pedir confirmación de lectura ("Enterado").
ALTER TABLE avisos
    ADD COLUMN requiere_confirmacion TINYINT(1) NOT NULL DEFAULT 1 AFTER imagen;
UPDATE avisos SET requiere_confirmacion = 0 WHERE importancia = 'informativo';

CREATE TABLE aviso_lecturas (
    aviso_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    leido_en DATETIME NOT NULL,
    PRIMARY KEY (aviso_id, usuario_id),
    INDEX idx_al_usuario (usuario_id),
    CONSTRAINT fk_al_aviso FOREIGN KEY (aviso_id) REFERENCES avisos(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
