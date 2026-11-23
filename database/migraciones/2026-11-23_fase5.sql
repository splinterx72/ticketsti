-- =====================================================================
--  Migración Fase 5 — Supervisores, ubicaciones físicas, avisos, tareas
--  y contraseña de admin local.
--
--  CÓMO APLICAR (una sola vez, sobre una instalación de la Fase 4):
--    1. Respalda la base: phpMyAdmin > Exportar > Rápido > SQL.
--    2. phpMyAdmin > selecciona tu base > Importar > este archivo > Continuar.
--  No borra datos. Si se ejecuta dos veces, se detiene en el primer CREATE TABLE
--  (las tablas ya existen) sin dañar nada.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
--  Ubicaciones físicas (las de la app; independientes del GECA)
-- ---------------------------------------------------------------------
CREATE TABLE instalaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ubicacion_tipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE niveles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(40) NOT NULL UNIQUE,
    orden SMALLINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ubicaciones_fisicas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instalacion_id INT UNSIGNED NOT NULL,
    tipo_id INT UNSIGNED NULL,
    nombre VARCHAR(150) NOT NULL,
    indicaciones TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ubicacion_fisica (instalacion_id, nombre),
    CONSTRAINT fk_ubf_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ubf_tipo FOREIGN KEY (tipo_id) REFERENCES ubicacion_tipos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ubicacion_fisica_niveles (
    ubicacion_fisica_id INT UNSIGNED NOT NULL,
    nivel_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ubicacion_fisica_id, nivel_id),
    CONSTRAINT fk_ufn_ubicacion FOREIGN KEY (ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufn_nivel FOREIGN KEY (nivel_id) REFERENCES niveles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ubicacion_fisica_alas (
    ubicacion_fisica_id INT UNSIGNED NOT NULL,
    ala ENUM('norte', 'sur', 'oriente', 'poniente') NOT NULL,
    PRIMARY KEY (ubicacion_fisica_id, ala),
    CONSTRAINT fk_ufa_ubicacion FOREIGN KEY (ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE geca_inmueble_instalacion (
    inmueble VARCHAR(150) NOT NULL PRIMARY KEY,
    instalacion_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_gii_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Responsabilidad por instalación (supervisores y agentes)
-- ---------------------------------------------------------------------
CREATE TABLE supervisor_instalaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supervisor_id INT UNSIGNED NOT NULL,
    instalacion_id INT UNSIGNED NOT NULL,
    tipo ENUM('titular', 'suplencia') NOT NULL DEFAULT 'titular',
    desde DATE NULL,
    hasta DATE NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_si_instalacion (instalacion_id),
    INDEX idx_si_supervisor (supervisor_id),
    CONSTRAINT fk_si_supervisor FOREIGN KEY (supervisor_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_si_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_si_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agente_instalaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agente_id INT UNSIGNED NOT NULL,
    instalacion_id INT UNSIGNED NOT NULL,
    tipo ENUM('base', 'prestamo') NOT NULL DEFAULT 'base',
    desde DATE NULL,
    hasta DATE NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_instalacion (instalacion_id),
    INDEX idx_ai_agente (agente_id),
    CONSTRAINT fk_ai_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Avisos en la página de inicio
-- ---------------------------------------------------------------------
CREATE TABLE avisos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    mensaje TEXT NOT NULL,
    importancia ENUM('informativo', 'importante', 'urgente') NOT NULL DEFAULT 'informativo',
    inicia_en DATETIME NOT NULL,
    termina_en DATETIME NOT NULL,
    alcance ENUM('todos', 'instalaciones') NOT NULL DEFAULT 'todos',
    autor_id INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_avisos_vigencia (inicia_en, termina_en),
    CONSTRAINT fk_aviso_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE aviso_instalaciones (
    aviso_id INT UNSIGNED NOT NULL,
    instalacion_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (aviso_id, instalacion_id),
    CONSTRAINT fk_avi_aviso FOREIGN KEY (aviso_id) REFERENCES avisos(id) ON DELETE CASCADE,
    CONSTRAINT fk_avi_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Tareas del supervisor a sus agentes
-- ---------------------------------------------------------------------
CREATE TABLE tareas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    supervisor_id INT UNSIGNED NULL,
    titulo VARCHAR(180) NOT NULL,
    instrucciones TEXT NOT NULL,
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL DEFAULT 'media',
    entrega_en DATETIME NOT NULL,
    evidencias_requeridas TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tareas_entrega (entrega_en),
    CONSTRAINT fk_tarea_supervisor FOREIGN KEY (supervisor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tarea_asignaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tarea_id INT UNSIGNED NOT NULL,
    agente_id INT UNSIGNED NOT NULL,
    estado ENUM('asignada', 'en_proceso', 'entregada', 'aceptada', 'devuelta') NOT NULL DEFAULT 'asignada',
    comentario_entrega TEXT NULL,
    entregado_en DATETIME NULL,
    comentario_revision TEXT NULL,
    revisado_en DATETIME NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tarea_agente (tarea_id, agente_id),
    CONSTRAINT fk_ta_tarea FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tarea_archivos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tarea_id INT UNSIGNED NOT NULL,
    asignacion_id INT UNSIGNED NULL,
    nombre_original VARCHAR(255) NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    tamano INT UNSIGNED NOT NULL,
    subido_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tarch_tarea FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    CONSTRAINT fk_tarch_asignacion FOREIGN KEY (asignacion_id) REFERENCES tarea_asignaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_tarch_subido_por FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Soporte: recordatorios, estado del sistema y verificación de correo
-- ---------------------------------------------------------------------
CREATE TABLE recordatorios_enviados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    clave VARCHAR(120) NOT NULL,
    enviado_en DATETIME NOT NULL,
    UNIQUE KEY uk_recordatorio (usuario_id, clave),
    CONSTRAINT fk_rec_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sistema_estado (
    clave VARCHAR(60) NOT NULL PRIMARY KEY,
    valor VARCHAR(255) NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verificaciones_email (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    email VARCHAR(150) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expira_en DATETIME NOT NULL,
    usado_en DATETIME NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ve_usuario (usuario_id),
    CONSTRAINT fk_ve_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Columnas nuevas en tablas existentes (se conservan las del GECA)
-- ---------------------------------------------------------------------
ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('usuario', 'agente_ti', 'supervisor', 'admin_ti') NOT NULL DEFAULT 'usuario';

ALTER TABLE usuarios
    ADD COLUMN email_verificado_en DATETIME NULL,
    ADD COLUMN admin_local_cambiado_en DATE NULL,
    ADD COLUMN habitual_ubicacion_fisica_id INT UNSIGNED NULL,
    ADD COLUMN habitual_nivel_id INT UNSIGNED NULL,
    ADD COLUMN habitual_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    ADD COLUMN habitual_referencia VARCHAR(255) NULL,
    ADD CONSTRAINT fk_usuario_hab_ubf FOREIGN KEY (habitual_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_usuario_hab_nivel FOREIGN KEY (habitual_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL;

ALTER TABLE equipos
    ADD COLUMN real_ubicacion_fisica_id INT UNSIGNED NULL,
    ADD COLUMN real_nivel_id INT UNSIGNED NULL,
    ADD COLUMN real_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    ADD CONSTRAINT fk_equipo_real_ubf FOREIGN KEY (real_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_equipo_real_nivel FOREIGN KEY (real_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL;

ALTER TABLE tickets
    ADD COLUMN instalacion_id INT UNSIGNED NULL,
    ADD COLUMN reportada_ubicacion_fisica_id INT UNSIGNED NULL,
    ADD COLUMN reportada_nivel_id INT UNSIGNED NULL,
    ADD COLUMN reportada_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    ADD COLUMN referencia VARCHAR(255) NULL,
    ADD CONSTRAINT fk_ticket_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ticket_rep_ubf FOREIGN KEY (reportada_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ticket_rep_nivel FOREIGN KEY (reportada_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL,
    ADD INDEX idx_tickets_instalacion (instalacion_id);

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO niveles (nombre, orden) VALUES
('Único', 0), ('Sótano', 1), ('PB', 2), ('1', 3), ('2', 4), ('3', 5), ('4', 6), ('5', 7),
('6', 8), ('7', 9), ('8', 10), ('9', 11), ('10', 12), ('Mezanine', 13);

INSERT INTO ubicacion_tipos (nombre) VALUES
('Edificio'), ('Nave'), ('Taller'), ('Área'), ('Caseta'), ('Otro');
