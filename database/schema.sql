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

-- ---------------------------------------------------------------------
--  Ubicaciones físicas (las de la app; independientes del GECA)
-- ---------------------------------------------------------------------
-- Zonas (Norte, Sur, Marina...): agrupan instalaciones bajo un coordinador.
CREATE TABLE zonas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE instalaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE,
    zona_id INT UNSIGNED NULL,
    modo_asignacion ENUM('manual', 'sugerida', 'automatica') NOT NULL DEFAULT 'sugerida',
    remedy_activo TINYINT(1) NOT NULL DEFAULT 1,
    ciudad VARCHAR(120) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_instalacion_zona FOREIGN KEY (zona_id) REFERENCES zonas(id) ON DELETE SET NULL
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
    jefe_ficha VARCHAR(20) NULL,
    jefe_nombre VARCHAR(150) NULL,
    email VARCHAR(150) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    telefono_ext VARCHAR(30) NULL,
    celular VARCHAR(20) NULL,
    ubicacion_habitual_id INT UNSIGNED NULL,
    rol ENUM('usuario', 'agente_ti', 'supervisor', 'coordinador', 'admin_ti') NOT NULL DEFAULT 'usuario',
    tipo_cuenta ENUM('geca', 'administracion', 'prueba') NOT NULL DEFAULT 'geca',
    registrado TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    email_verificado_en DATETIME NULL,
    admin_local_cambiado_en DATE NULL,
    habitual_ubicacion_fisica_id INT UNSIGNED NULL,
    habitual_nivel_id INT UNSIGNED NULL,
    habitual_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    habitual_referencia VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_hab_ubf FOREIGN KEY (habitual_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    CONSTRAINT fk_usuario_hab_nivel FOREIGN KEY (habitual_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL,
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
    inventario VARCHAR(40) NULL,
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
    fuera_consola VARCHAR(120) NULL,
    motivo_baja VARCHAR(255) NULL,
    tipo_componente VARCHAR(30) NULL,
    numero_orden VARCHAR(60) NULL,
    lote VARCHAR(60) NULL,
    ubicacion_referencia VARCHAR(150) NULL,
    entidad_federativa VARCHAR(80) NULL,
    municipio VARCHAR(100) NULL,
    tipo_asignacion VARCHAR(80) NULL,
    pr_folio VARCHAR(40) NULL,
    pr_fecha DATETIME NULL,
    pr_estado_resguardo VARCHAR(60) NULL,
    pr_nombre VARCHAR(150) NULL,
    pr_ficha VARCHAR(20) NULL,
    pr_eps VARCHAR(80) NULL,
    pr_zona VARCHAR(100) NULL,
    serie_monitor1 VARCHAR(60) NULL,
    marca_monitor1 VARCHAR(60) NULL,
    modelo_monitor1 VARCHAR(80) NULL,
    serie_monitor2 VARCHAR(60) NULL,
    marca_monitor2 VARCHAR(60) NULL,
    modelo_monitor2 VARCHAR(80) NULL,
    ultima_carga_geca_id INT UNSIGNED NULL,
    vigente_en_geca TINYINT(1) NOT NULL DEFAULT 1,
    real_ubicacion_fisica_id INT UNSIGNED NULL,
    real_nivel_id INT UNSIGNED NULL,
    real_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipo_real_ubf FOREIGN KEY (real_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_real_nivel FOREIGN KEY (real_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_geca FOREIGN KEY (ubicacion_geca_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_real FOREIGN KEY (ubicacion_real_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_carga_geca FOREIGN KEY (ultima_carga_geca_id) REFERENCES cargas_geca(id) ON DELETE SET NULL,
    INDEX idx_equipo_usuario (usuario_id),
    INDEX idx_equipo_monitor1 (serie_monitor1),
    INDEX idx_equipo_monitor2 (serie_monitor2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    capturado_por INT UNSIGNED NULL,
    actualizado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_garantia_capturo FOREIGN KEY (capturado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_garantia_actualizo FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_garantia_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    INDEX idx_garantia_vencimiento (fecha_vencimiento, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categoría de la acción (la elige quien crea el ticket; el Admin TI la edita).
CREATE TABLE categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL UNIQUE,
    descripcion VARCHAR(500) NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE estados (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    color_hex CHAR(7) NOT NULL DEFAULT '#6c757d',
    es_cierre TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tiempos de atención (SLA) por prioridad, en horas naturales. Los edita el Admin TI.
CREATE TABLE sla_prioridades (
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL PRIMARY KEY,
    horas_respuesta SMALLINT UNSIGNED NOT NULL,
    horas_resolucion SMALLINT UNSIGNED NOT NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sla_prioridades (prioridad, horas_respuesta, horas_resolucion) VALUES
('critica', 1, 4), ('alta', 2, 8), ('media', 4, 24), ('baja', 8, 72);

CREATE TABLE tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    tipo_ticket ENUM('incidencia', 'solicitud', 'actualizacion_ubicacion', 'garantia', 'formateo', 'tarea', 'actualizaciones', 'levantamiento', 'instruccion', 'formateos', 'bajas', 'altas') NOT NULL DEFAULT 'incidencia',
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NOT NULL,
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL DEFAULT 'media',
    usuario_solicitante_id INT UNSIGNED NOT NULL,
    creado_por_id INT UNSIGNED NULL,
    agente_asignado_id INT UNSIGNED NULL,
    sugerido_agente_id INT UNSIGNED NULL,
    folio_remedy VARCHAR(40) NULL,
    categoria_id INT UNSIGNED NULL,
    departamento_id INT UNSIGNED NOT NULL,
    estado_id TINYINT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NULL,
    componente_afectado ENUM('equipo', 'monitor_1', 'monitor_2', 'general') NOT NULL DEFAULT 'general',
    hostname_reportado VARCHAR(30) NULL,
    ubicacion_reportada_id INT UNSIGNED NULL,
    discrepancia_ubicacion TINYINT(1) NOT NULL DEFAULT 0,
    instalacion_id INT UNSIGNED NULL,
    reportada_ubicacion_fisica_id INT UNSIGNED NULL,
    reportada_nivel_id INT UNSIGNED NULL,
    reportada_ala ENUM('na', 'norte', 'sur', 'oriente', 'poniente') NULL,
    referencia VARCHAR(255) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    atendido_en TIMESTAMP NULL,
    cerrado_en TIMESTAMP NULL,
    CONSTRAINT fk_ticket_instalacion FOREIGN KEY (instalacion_id) REFERENCES instalaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_rep_ubf FOREIGN KEY (reportada_ubicacion_fisica_id) REFERENCES ubicaciones_fisicas(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_rep_nivel FOREIGN KEY (reportada_nivel_id) REFERENCES niveles(id) ON DELETE SET NULL,
    INDEX idx_tickets_instalacion (instalacion_id),
    CONSTRAINT fk_ticket_solicitante FOREIGN KEY (usuario_solicitante_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_creado_por FOREIGN KEY (creado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_agente FOREIGN KEY (agente_asignado_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_sugerido FOREIGN KEY (sugerido_agente_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_depto FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_estado FOREIGN KEY (estado_id) REFERENCES estados(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_ubicacion FOREIGN KEY (ubicacion_reportada_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    INDEX idx_tickets_tablero (estado_id, departamento_id),
    INDEX idx_tickets_discrepancia (discrepancia_ubicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varios equipos por ticket (tickets de TI: tareas, levantamientos, altas, bajas…).
CREATE TABLE ticket_equipos (
    ticket_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, equipo_id),
    CONSTRAINT fk_tequ_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tequ_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
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

-- Adjuntos de tickets (fotos, capturas, PDF) al crear o en comentarios.
CREATE TABLE ticket_archivos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    comentario_id INT UNSIGNED NULL,
    ruta VARCHAR(255) NOT NULL,
    nombre_original VARCHAR(200) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    tamano INT UNSIGNED NOT NULL,
    interno TINYINT(1) NOT NULL DEFAULT 0,
    subido_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tkarch_ticket (ticket_id),
    CONSTRAINT fk_tkarch_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tkarch_comentario FOREIGN KEY (comentario_id) REFERENCES comentarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_tkarch_subido_por FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Responsabilidad por instalación (supervisores y agentes)
-- ---------------------------------------------------------------------
-- Coordinadores por zona (titular o suplencia con fechas), igual que los supervisores.
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
    imagen VARCHAR(255) NULL,
    requiere_confirmacion TINYINT(1) NOT NULL DEFAULT 1,
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

-- Acuse de lectura: quién pulsó "Enterado" y cuándo.
CREATE TABLE aviso_lecturas (
    aviso_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    leido_en DATETIME NOT NULL,
    PRIMARY KEY (aviso_id, usuario_id),
    INDEX idx_al_usuario (usuario_id),
    CONSTRAINT fk_al_aviso FOREIGN KEY (aviso_id) REFERENCES avisos(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
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
    meta_unidad ENUM('equipos', 'usuarios', 'otro') NULL,
    meta_descripcion VARCHAR(60) NULL,
    meta_cantidad INT UNSIGNED NULL,
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
    completa_avisada_en DATETIME NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tarea_agente (tarea_id, agente_id),
    CONSTRAINT fk_ta_tarea FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_agente FOREIGN KEY (agente_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hoja de reportes de cada agente en su tarea: texto libre, cuántos equipos
-- o usuarios atendió (suma al avance) y, si salió de un ticket, cuál.
CREATE TABLE tarea_avances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asignacion_id INT UNSIGNED NOT NULL,
    texto TEXT NOT NULL,
    cantidad INT UNSIGNED NOT NULL DEFAULT 0,
    ticket_id INT UNSIGNED NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tav_asignacion (asignacion_id),
    CONSTRAINT fk_tav_asignacion FOREIGN KEY (asignacion_id) REFERENCES tarea_asignaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_tav_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tav_creo FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tarea_archivos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tarea_id INT UNSIGNED NOT NULL,
    asignacion_id INT UNSIGNED NULL,
    avance_id INT UNSIGNED NULL,
    nombre_original VARCHAR(255) NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    tamano INT UNSIGNED NOT NULL,
    subido_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tarch_tarea FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    CONSTRAINT fk_tarch_asignacion FOREIGN KEY (asignacion_id) REFERENCES tarea_asignaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_tarch_avance FOREIGN KEY (avance_id) REFERENCES tarea_avances(id) ON DELETE CASCADE,
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

-- Módulos que el Admin TI enciende/apaga y qué roles los usan (admin_ti siempre).
CREATE TABLE modulos (
    clave VARCHAR(40) NOT NULL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    roles VARCHAR(120) NOT NULL DEFAULT '',
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes editables desde la aplicación (p. ej. correo de Remedy).
CREATE TABLE ajustes (
    clave VARCHAR(60) NOT NULL PRIMARY KEY,
    valor TEXT NULL,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ajuste_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plantillas de correos comunes que TI envía a los trabajadores.
CREATE TABLE plantillas_correo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    asunto VARCHAR(180) NOT NULL,
    cuerpo TEXT NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plantilla_creado FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de correos enviados con plantilla.
CREATE TABLE correos_enviados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plantilla_id INT UNSIGNED NULL,
    destinatario_id INT UNSIGNED NULL,
    email VARCHAR(150) NOT NULL,
    ticket_id INT UNSIGNED NULL,
    asunto VARCHAR(180) NOT NULL,
    enviado_por INT UNSIGNED NULL,
    enviado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ce_destinatario (destinatario_id),
    CONSTRAINT fk_ce_plantilla FOREIGN KEY (plantilla_id) REFERENCES plantillas_correo(id) ON DELETE SET NULL,
    CONSTRAINT fk_ce_destinatario FOREIGN KEY (destinatario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ce_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_ce_enviado FOREIGN KEY (enviado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Formatos oficiales (p. ej. autorización de formateo). Cada plantilla es la
-- versión de un año: logos, textos y motivos. Lo que ya se firmó apunta a su
-- versión y nunca cambia; si se edita una versión usada, se crea otra.
CREATE TABLE formato_plantillas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(40) NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    titulo VARCHAR(150) NOT NULL,
    contenido MEDIUMTEXT NOT NULL,
    logo_izq VARCHAR(120) NULL,
    logo_der VARCHAR(120) NULL,
    logo_pie VARCHAR(120) NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_plantilla_version (clave, anio, version),
    CONSTRAINT fk_fplant_creado FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documento emitido (folio FF-AAAA-NNNNNN). Firmas en orden: usuario →
-- responsable (jefe) → TI. Al completarse se congela una copia de los datos.
CREATE TABLE formatos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    codigo CHAR(8) NOT NULL,
    plantilla_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL,
    usuario_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NULL,
    motivos TEXT NULL,
    metodo VARCHAR(80) NULL,
    datos MEDIUMTEXT NULL,
    estado ENUM('pendiente_usuario', 'pendiente_responsable', 'pendiente_ti', 'completo', 'cancelado') NOT NULL DEFAULT 'pendiente_usuario',
    archivo_autografo VARCHAR(120) NULL,
    creado_por INT UNSIGNED NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completado_en TIMESTAMP NULL,
    cancelado_en TIMESTAMP NULL,
    motivo_cancelacion VARCHAR(255) NULL,
    INDEX idx_formato_ticket (ticket_id),
    INDEX idx_formato_usuario (usuario_id),
    CONSTRAINT fk_formato_plantilla FOREIGN KEY (plantilla_id) REFERENCES formato_plantillas(id) ON DELETE RESTRICT,
    CONSTRAINT fk_formato_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_formato_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_formato_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
    CONSTRAINT fk_formato_creado FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Firma de cada rol: dibujada (en su sesión o presencial en el dispositivo
-- del técnico) o autógrafa (papel escaneado). Queda quién la capturó y cuándo.
CREATE TABLE formato_firmas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    formato_id INT UNSIGNED NOT NULL,
    rol ENUM('usuario', 'responsable', 'ti') NOT NULL,
    firmante_id INT UNSIGNED NULL,
    nombre VARCHAR(150) NOT NULL,
    ficha VARCHAR(20) NOT NULL,
    imagen VARCHAR(120) NULL,
    modo ENUM('sesion', 'presencial', 'autografa') NOT NULL,
    motivo VARCHAR(255) NULL,
    capturado_por INT UNSIGNED NULL,
    ip VARCHAR(45) NULL,
    firmado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_firma_rol (formato_id, rol),
    CONSTRAINT fk_ffirma_formato FOREIGN KEY (formato_id) REFERENCES formatos(id) ON DELETE CASCADE,
    CONSTRAINT fk_ffirma_firmante FOREIGN KEY (firmante_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ffirma_capturo FOREIGN KEY (capturado_por) REFERENCES usuarios(id) ON DELETE SET NULL
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

INSERT INTO categorias (nombre, descripcion, orden) VALUES
('Hardware / Equipo de Cómputo', 'Fallas en PC/Laptop, monitores, teclados, baterías, periféricos, solicitud de equipo nuevo o préstamo.', 1),
('Impresión y Escaneo', 'Multifuncionales, atascos de papel, cambio de tóner, configuración de red en impresoras, escáneres.', 2),
('Software y Aplicaciones', 'Paquetería de oficina (Office), navegadores, lectores PDF, instalación o actualización de programas autorizados.', 3),
('Sistemas Institucionales / ERP', 'Módulos de SAP, SAC, sistemas internos de nómina, inventarios, facturación o portales corporativos.', 4),
('Redes y Conectividad', 'Fallas de internet, cableado estructurado (nodos), Wi-Fi, VPN, enlaces de datos o lentitud en la red.', 5),
('Accesos, Cuentas y Seguridad', 'Restablecimiento de contraseñas, desbloqueo de usuario (Active Directory), permisos a carpetas compartidas, BitLocker, antivirus.', 6),
('Correo y Colaboración', 'Configuración de correo institucional, listas de distribución, respaldo de buzones, Teams, videoconferencias.', 7),
('Telefonía y Comunicaciones', 'Extensiones IP, telefonía fija, celulares corporativos, radios o diademas.', 8),
('Infraestructura y Servidores', '(Más orientado a nivel interno de TI) Virtualización, bases de datos, respaldos, cuartos de comunicaciones (Sites) o energía (UPS).', 9),
('Soporte Administrativo / Movimientos', 'Resguardos de equipo, bajas, dictámenes técnicos, pases de salida, garantías con proveedor o capacitación.', 10);

INSERT INTO niveles (nombre, orden) VALUES
('Único', 0), ('Sótano', 1), ('PB', 2), ('1', 3), ('2', 4), ('3', 5), ('4', 6), ('5', 7),
('6', 8), ('7', 9), ('8', 10), ('9', 11), ('10', 12), ('Mezanine', 13);

INSERT INTO ubicacion_tipos (nombre) VALUES
('Edificio'), ('Nave'), ('Taller'), ('Área'), ('Caseta'), ('Otro');

INSERT INTO modulos (clave, nombre, descripcion, roles) VALUES
('garantias', 'Garantías', 'TI registra y modifica pólizas; el trabajador ve el historial de sus equipos.', 'usuario,agente_ti,supervisor,coordinador'),
('bitlocker', 'BitLocker', 'Identificador y llave de recuperación por equipo.', 'usuario,agente_ti,supervisor,coordinador'),
('inventario', 'Inventario', 'Consulta de equipos por instalación.', 'agente_ti,supervisor,coordinador'),
('reportes', 'Reportes', 'Indicadores, tablero de agentes y exportación.', 'agente_ti,supervisor,coordinador'),
('tareas', 'Tareas', 'Tareas del supervisor con entregas y evidencias.', 'agente_ti,supervisor,coordinador'),
('avisos', 'Publicar avisos', 'Publicar avisos y noticias (leerlos es para todos).', 'supervisor,coordinador'),
('plantillas', 'Correos con plantilla', 'Enviar correos comunes a los trabajadores.', 'agente_ti,supervisor,coordinador'),
('formatos', 'Formatos con firma', 'Autorización de formateo y otros formatos oficiales: firmas del usuario, su jefe y TI.', 'usuario,agente_ti,supervisor,coordinador');

INSERT INTO plantillas_correo (nombre, asunto, cuerpo) VALUES
('Mantenimiento programado', 'Mantenimiento programado a tu equipo', 'Hola {nombre}:\n\nTe avisamos que realizaremos mantenimiento a tu equipo. Por favor guarda tu información y deja el equipo encendido.\n\nAtentamente,\n{agente}\nMesa de Ayuda TI'),
('Confirmar solución', '[{folio}] ¿Quedó resuelto tu ticket?', 'Hola {nombre}:\n\nAtendimos tu ticket {folio} "{titulo}". Por favor confírmanos si quedó resuelto respondiendo desde el sistema.\n\nAtentamente,\n{agente}\nMesa de Ayuda TI'),
('Doble autenticación', 'Necesitamos comunicarnos contigo', 'Hola {nombre}:\n\nPara continuar con tu atención necesitamos que nos dictes el código de doble autenticación que aparece en tu pantalla. Te llamaremos a tu extensión o celular; si prefieres, comunícate con {agente}.\n\nMesa de Ayuda TI');
