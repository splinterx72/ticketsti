-- =====================================================================
-- Migración: formatos oficiales con firmas (autorización de formateo),
-- inventario del equipo, folio de Remedy en el ticket y ciudad por instalación.
-- Aplicar DESPUÉS de 2026-11-30_delegaciones.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

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

-- Tipo de ticket "Solicitud de formateo" y folio de Remedy (se captura cuando llega).
ALTER TABLE tickets
    MODIFY COLUMN tipo_ticket ENUM('incidencia', 'solicitud', 'actualizacion_ubicacion', 'garantia', 'formateo') NOT NULL DEFAULT 'incidencia',
    ADD COLUMN folio_remedy VARCHAR(40) NULL AFTER sugerido_agente_id;

-- Número de inventario PEMEX o de la compañía propietaria (lo captura TI).
ALTER TABLE equipos
    ADD COLUMN inventario VARCHAR(40) NULL AFTER es_principal;

-- Ciudad para "Lugar y fecha" de los formatos.
ALTER TABLE instalaciones
    ADD COLUMN ciudad VARCHAR(120) NULL AFTER remedy_activo;
