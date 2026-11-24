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

-- Rol Coordinador (encargado de una o varias zonas).
ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('usuario', 'agente_ti', 'supervisor', 'coordinador', 'admin_ti') NOT NULL DEFAULT 'usuario';

CREATE TABLE zonas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE instalaciones
    ADD COLUMN zona_id INT UNSIGNED NULL AFTER nombre,
    ADD CONSTRAINT fk_instalacion_zona FOREIGN KEY (zona_id) REFERENCES zonas(id) ON DELETE SET NULL;

CREATE TABLE coordinador_zonas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinador_id INT UNSIGNED NOT NULL,
    zona_id INT UNSIGNED NOT NULL,
    tipo ENUM('titular', 'suplencia') NOT NULL DEFAULT 'titular',
    desde DATE NULL,
    hasta DATE NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cz_zona (zona_id),
    INDEX idx_cz_coordinador (coordinador_id),
    CONSTRAINT fk_cz_coordinador FOREIGN KEY (coordinador_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_cz_zona FOREIGN KEY (zona_id) REFERENCES zonas(id) ON DELETE CASCADE,
    CONSTRAINT fk_cz_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
