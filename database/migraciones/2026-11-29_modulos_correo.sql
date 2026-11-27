-- =====================================================================
-- Migración: módulos con control del Admin TI, correo de Remedy configurable
-- (y por instalación), plantillas de correo y quién captura las garantías.
-- Aplicar DESPUÉS de 2026-11-28_bitlocker_asignacion.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

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

-- Cada supervisor/coordinador decide si los tickets de su instalación van a Remedy.
ALTER TABLE instalaciones
    ADD COLUMN remedy_activo TINYINT(1) NOT NULL DEFAULT 1 AFTER modo_asignacion;

-- Quién registró y quién modificó por última vez cada garantía.
ALTER TABLE garantias
    ADD COLUMN capturado_por INT UNSIGNED NULL AFTER notas,
    ADD COLUMN actualizado_por INT UNSIGNED NULL AFTER capturado_por,
    ADD COLUMN actualizado_en TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER creado_en,
    ADD CONSTRAINT fk_garantia_capturo FOREIGN KEY (capturado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_garantia_actualizo FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL;

INSERT INTO modulos (clave, nombre, descripcion, roles) VALUES
('garantias', 'Garantías', 'TI registra y modifica pólizas; el trabajador ve el historial de sus equipos.', 'usuario,agente_ti,supervisor,coordinador'),
('bitlocker', 'BitLocker', 'Identificador y llave de recuperación por equipo.', 'usuario,agente_ti,supervisor,coordinador'),
('inventario', 'Inventario', 'Consulta de equipos por instalación.', 'agente_ti,supervisor,coordinador'),
('reportes', 'Reportes', 'Indicadores, tablero de agentes y exportación.', 'agente_ti,supervisor,coordinador'),
('tareas', 'Tareas', 'Tareas del supervisor con entregas y evidencias.', 'agente_ti,supervisor,coordinador'),
('avisos', 'Publicar avisos', 'Publicar avisos y noticias (leerlos es para todos).', 'supervisor,coordinador'),
('plantillas', 'Correos con plantilla', 'Enviar correos comunes a los trabajadores.', 'agente_ti,supervisor,coordinador');

INSERT INTO plantillas_correo (nombre, asunto, cuerpo) VALUES
('Mantenimiento programado', 'Mantenimiento programado a tu equipo', 'Hola {nombre}:\n\nTe avisamos que realizaremos mantenimiento a tu equipo. Por favor guarda tu información y deja el equipo encendido.\n\nAtentamente,\n{agente}\nMesa de Ayuda TI'),
('Confirmar solución', '[{folio}] ¿Quedó resuelto tu ticket?', 'Hola {nombre}:\n\nAtendimos tu ticket {folio} "{titulo}". Por favor confírmanos si quedó resuelto respondiendo desde el sistema.\n\nAtentamente,\n{agente}\nMesa de Ayuda TI'),
('Doble autenticación', 'Necesitamos comunicarnos contigo', 'Hola {nombre}:\n\nPara continuar con tu atención necesitamos que nos dictes el código de doble autenticación que aparece en tu pantalla. Te llamaremos a tu extensión o celular; si prefieres, comunícate con {agente}.\n\nMesa de Ayuda TI');
