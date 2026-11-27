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
