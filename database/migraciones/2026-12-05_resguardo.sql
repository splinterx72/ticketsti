-- =====================================================================
-- Migración: resguardo de equipos (lo que deja el trabajador y equipos
-- disponibles para asignar) y su módulo.
-- Aplicar DESPUÉS de 2026-12-04_tareas_reportes.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Resguardo de equipos (equipos que dejan los trabajadores por baja, cambio,
-- renuncia… y esperan asignación). Las actas de baja y responsivas las genera
-- el GECA; aquí se registra lo que se dejó (componentes y accesorios) y, si se
-- quiere, el acta escaneada.
CREATE TABLE recepciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    usuario_id INT UNSIGNED NULL,
    ficha_entrega VARCHAR(20) NULL,
    nombre_entrega VARCHAR(150) NULL,
    motivo VARCHAR(40) NOT NULL,
    instalacion_id INT UNSIGNED NULL,
    observaciones TEXT NULL,
    acta_ruta VARCHAR(255) NULL,
    acta_nombre VARCHAR(255) NULL,
    acta_mime VARCHAR(100) NULL,
    acta_tamano INT UNSIGNED NULL,
    recibido_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recep_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_recep_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_recep_recibio FOREIGN KEY (recibido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada equipo recibido: lo que trae, su estado y, al asignarse, a quién
-- (queda "pendiente en GECA" hasta que la carga semanal lo trae a su nombre).
CREATE TABLE equipos_resguardo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recepcion_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NOT NULL,
    estado ENUM('en_resguardo', 'disponible', 'dictamen', 'baja_definitiva', 'asignado') NOT NULL DEFAULT 'en_resguardo',
    condicion ENUM('bueno', 'regular', 'malo') NOT NULL DEFAULT 'bueno',
    accesorios TEXT NULL,
    observaciones VARCHAR(500) NULL,
    asignado_a_id INT UNSIGNED NULL,
    asignado_por INT UNSIGNED NULL,
    asignado_en DATETIME NULL,
    asignacion_nota VARCHAR(500) NULL,
    confirmado_geca_en DATETIME NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_eres_estado (estado),
    INDEX idx_eres_equipo (equipo_id),
    CONSTRAINT fk_eres_recepcion FOREIGN KEY (recepcion_id) REFERENCES recepciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_eres_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    CONSTRAINT fk_eres_asignado FOREIGN KEY (asignado_a_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_eres_asigno FOREIGN KEY (asignado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_eres_actualizo FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO modulos (clave, nombre, descripcion, roles) VALUES
('resguardo', 'Resguardo de equipos', 'Equipos que dejan los trabajadores (baja, cambio…) y equipos disponibles para asignar.', 'agente_ti,supervisor,coordinador');
