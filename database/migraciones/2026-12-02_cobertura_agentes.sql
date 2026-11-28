-- =====================================================================
-- Migración: cobertura de agentes por ubicación y pausas del supervisor.
-- Aplicar DESPUÉS de 2026-12-01_formatos.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Cobertura por ubicación: qué ubicaciones de una instalación atiende cada
-- agente (sin filas en esa instalación = todas). La decide el supervisor.
CREATE TABLE agente_ubicaciones (
    agente_id INT UNSIGNED NOT NULL,
    ubicacion_fisica_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (agente_id, ubicacion_fisica_id),
    CONSTRAINT fk_aubi_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_aubi_ubicacion FOREIGN KEY (ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pausas que pone el supervisor: en una ubicación o en toda la instalación
-- (ubicacion_fisica_id NULL). Mientras sigan vigentes, el agente no recibe
-- asignaciones automáticas ni sugeridas ahí.
CREATE TABLE agente_pausas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agente_id INT UNSIGNED NOT NULL,
    instalacion_id INT UNSIGNED NOT NULL,
    ubicacion_fisica_id INT UNSIGNED NULL,
    motivo VARCHAR(255) NULL,
    hasta DATE NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apau_agente (agente_id, instalacion_id),
    CONSTRAINT fk_apau_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_apau_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_apau_ubicacion FOREIGN KEY (ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE CASCADE,
    CONSTRAINT fk_apau_creo FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
