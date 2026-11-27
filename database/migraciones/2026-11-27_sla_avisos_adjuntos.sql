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

-- Tiempos de atención (SLA) por prioridad, en horas naturales.
CREATE TABLE sla_prioridades (
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL PRIMARY KEY,
    horas_respuesta SMALLINT UNSIGNED NOT NULL,
    horas_resolucion SMALLINT UNSIGNED NOT NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sla_prioridades (prioridad, horas_respuesta, horas_resolucion) VALUES
('critica', 1, 4), ('alta', 2, 8), ('media', 4, 24), ('baja', 8, 72);

-- Primera atención de TI (para medir el tiempo de respuesta).
ALTER TABLE tickets
    ADD COLUMN atendido_en TIMESTAMP NULL AFTER actualizado_en;
-- Tickets anteriores: se toma como primera atención su asignación o cierre conocidos.
UPDATE tickets SET atendido_en = COALESCE(cerrado_en, actualizado_en) WHERE agente_asignado_id IS NOT NULL OR cerrado_en IS NOT NULL;
