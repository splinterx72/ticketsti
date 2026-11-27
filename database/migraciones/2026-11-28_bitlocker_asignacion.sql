-- =====================================================================
-- Migración: BitLocker por equipo, celular del usuario, disponibilidad y
-- especialidades de agentes y asignación sugerida de tickets.
-- Aplicar DESPUÉS de 2026-11-27_sla_avisos_adjuntos.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- BitLocker: identificador de la llave de recuperación y, opcionalmente, la llave cifrada.
CREATE TABLE equipo_bitlocker (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipo_id INT UNSIGNED NOT NULL,
    unidad VARCHAR(10) NOT NULL DEFAULT 'C:',
    identificador VARCHAR(40) NOT NULL,
    llave_cifrada TEXT NULL,
    nota VARCHAR(255) NULL,
    capturado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bl_equipo (equipo_id),
    CONSTRAINT fk_bl_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    CONSTRAINT fk_bl_capturado FOREIGN KEY (capturado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: quién vio una llave de BitLocker, cuándo y para qué.
CREATE TABLE bitlocker_consultas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bitlocker_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL,
    motivo VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NULL,
    consultado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_blc_bitlocker (bitlocker_id),
    CONSTRAINT fk_blc_bitlocker FOREIGN KEY (bitlocker_id) REFERENCES equipo_bitlocker(id) ON DELETE CASCADE,
    CONSTRAINT fk_blc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Celular opcional del usuario (para la doble autenticación y para localizarlo).
ALTER TABLE usuarios
    ADD COLUMN celular VARCHAR(20) NULL AFTER telefono_ext;

-- Disponibilidad del agente para la asignación sugerida/automática.
-- fuera/vacaciones no reciben tickets; ocupado recibe con menor prioridad.
-- Al pasar la fecha "hasta" el agente vuelve a contar como disponible.
CREATE TABLE agente_estado (
    usuario_id INT UNSIGNED NOT NULL PRIMARY KEY,
    estado ENUM('disponible', 'ocupado', 'fuera', 'vacaciones') NOT NULL DEFAULT 'disponible',
    hasta DATE NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_aest_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_aest_actualizo FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Especialidades: departamentos que atiende el agente (sin filas = atiende todos).
CREATE TABLE agente_departamentos (
    agente_id INT UNSIGNED NOT NULL,
    departamento_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (agente_id, departamento_id),
    CONSTRAINT fk_adep_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_adep_depto FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modo de asignación por instalación (manual | sugerida | automatica).
ALTER TABLE instalaciones
    ADD COLUMN modo_asignacion ENUM('manual', 'sugerida', 'automatica') NOT NULL DEFAULT 'sugerida' AFTER zona_id;

-- Agente sugerido al crear el ticket (TI lo acepta con un clic).
ALTER TABLE tickets
    ADD COLUMN sugerido_agente_id INT UNSIGNED NULL AFTER agente_asignado_id,
    ADD CONSTRAINT fk_ticket_sugerido FOREIGN KEY (sugerido_agente_id) REFERENCES usuarios(id) ON DELETE SET NULL;
