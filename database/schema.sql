-- =====================================================================
--  Mesa de Ayuda TI y Control de Resguardos (GECA)
--  Esquema para MySQL 5.7+ / MariaDB 10.3+  — listo para phpMyAdmin (cPanel)
--
--  CÓMO IMPORTAR:
--    1. Crea la base con el asistente "MySQL® Databases" de cPanel
--       (quedará como  usuario_mesaayuda) y asígnale un usuario con
--       TODOS LOS PRIVILEGIOS.
--    2. Abre phpMyAdmin, selecciona esa base en el panel izquierdo.
--    3. Pestaña "Importar" > elige este archivo > Continuar.
--
--  Este script NO incluye CREATE DATABASE ni USE a propósito.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '-06:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE ubicaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    eps VARCHAR(80) NOT NULL,
    subdireccion VARCHAR(120) NOT NULL,
    gerencia VARCHAR(120) NOT NULL,
    zona VARCHAR(100) NULL,
    sector VARCHAR(100) NULL,
    inmueble VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_ubicacion_completa (eps, subdireccion, gerencia, zona, sector, inmueble)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE cargas_geca (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    usuario_ti_id INT UNSIGNED NULL,
    total_registros INT UNSIGNED NOT NULL DEFAULT 0,
    nuevos_insertados INT UNSIGNED NOT NULL DEFAULT 0,
    actualizados INT UNSIGNED NOT NULL DEFAULT 0,
    marcados_no_vigentes INT UNSIGNED NOT NULL DEFAULT 0,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ficha VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    jefe_nombre VARCHAR(150) NULL,
    email VARCHAR(150) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    telefono_ext VARCHAR(30) NULL,
    ubicacion_habitual_id INT UNSIGNED NULL,
    rol ENUM('usuario', 'agente_ti', 'admin_ti') NOT NULL DEFAULT 'usuario',
    registrado TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_ubicacion
        FOREIGN KEY (ubicacion_habitual_id) REFERENCES ubicaciones(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    serie VARCHAR(60) NOT NULL UNIQUE,
    usuario_id INT UNSIGNED NULL,
    ubicacion_geca_id INT UNSIGNED NULL,
    ubicacion_real_id INT UNSIGNED NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    folio_resguardo VARCHAR(40) NULL,
    fecha_resguardo DATE NULL,
    estado_resguardo VARCHAR(60) NULL,
    numero_contrato VARCHAR(60) NULL,
    estado_inventario VARCHAR(60) NULL,
    marca VARCHAR(60) NULL,
    modelo VARCHAR(80) NULL,
    perfil VARCHAR(80) NULL,
    usuario_fecha_aceptacion DATETIME NULL,
    ti_fecha_validacion DATETIME NULL,
    fecha_baja DATETIME NULL,
    fecha_carga_resguardo DATETIME NULL,
    fuera_consola VARCHAR(40) NULL,
    motivo_baja VARCHAR(255) NULL,
    serie_monitor1 VARCHAR(60) NULL,
    marca_monitor1 VARCHAR(60) NULL,
    modelo_monitor1 VARCHAR(80) NULL,
    serie_monitor2 VARCHAR(60) NULL,
    marca_monitor2 VARCHAR(60) NULL,
    modelo_monitor2 VARCHAR(80) NULL,
    ultima_carga_geca_id INT UNSIGNED NULL,
    vigente_en_geca TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_geca FOREIGN KEY (ubicacion_geca_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_real FOREIGN KEY (ubicacion_real_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_carga_geca FOREIGN KEY (ultima_carga_geca_id) REFERENCES cargas_geca(id) ON DELETE SET NULL,
    INDEX idx_equipo_usuario (usuario_id),
    INDEX idx_equipo_monitor1 (serie_monitor1),
    INDEX idx_equipo_monitor2 (serie_monitor2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garantias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipo_id INT UNSIGNED NOT NULL,
    elemento_cubierto ENUM('equipo', 'monitor_1', 'monitor_2') NOT NULL DEFAULT 'equipo',
    proveedor VARCHAR(120) NOT NULL,
    numero_poliza_contrato VARCHAR(80) NULL,
    fecha_inicio DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    estado ENUM('vigente', 'en_reclamacion', 'vencida') NOT NULL DEFAULT 'vigente',
    notas TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_garantia_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    INDEX idx_garantia_vencimiento (fecha_vencimiento, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estados (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    color_hex CHAR(7) NOT NULL DEFAULT '#6c757d',
    es_cierre TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    tipo_ticket ENUM('incidencia', 'solicitud', 'actualizacion_ubicacion', 'garantia') NOT NULL DEFAULT 'incidencia',
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NOT NULL,
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL DEFAULT 'media',
    usuario_solicitante_id INT UNSIGNED NOT NULL,
    agente_asignado_id INT UNSIGNED NULL,
    departamento_id INT UNSIGNED NOT NULL,
    estado_id TINYINT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NULL,
    componente_afectado ENUM('equipo', 'monitor_1', 'monitor_2', 'general') NOT NULL DEFAULT 'general',
    hostname_reportado VARCHAR(30) NULL,
    ubicacion_reportada_id INT UNSIGNED NULL,
    discrepancia_ubicacion TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cerrado_en TIMESTAMP NULL,
    CONSTRAINT fk_ticket_solicitante FOREIGN KEY (usuario_solicitante_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_agente FOREIGN KEY (agente_asignado_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_depto FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_estado FOREIGN KEY (estado_id) REFERENCES estados(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_ubicacion FOREIGN KEY (ubicacion_reportada_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    INDEX idx_tickets_tablero (estado_id, departamento_id),
    INDEX idx_tickets_discrepancia (discrepancia_ubicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comentarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    mensaje TEXT NOT NULL,
    es_nota_interna TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comentario_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_comentario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  Datos semilla iniciales
-- ---------------------------------------------------------------------
INSERT INTO estados (nombre, color_hex, es_cierre) VALUES
('Abierto', '#0d6efd', 0),
('En proceso', '#ffc107', 0),
('En espera de usuario', '#fd7e14', 0),
('Resuelto', '#198754', 1),
('Cerrado', '#6c757d', 1);

INSERT INTO departamentos (nombre, descripcion) VALUES
('Soporte Técnico en Sitio', 'Atención a equipos de cómputo, monitores y periféricos'),
('Control de Resguardos GECA', 'Actualización de ubicaciones y validación de inventario'),
('Garantías y Proveedores', 'Seguimiento de pólizas y garantías de hardware');
