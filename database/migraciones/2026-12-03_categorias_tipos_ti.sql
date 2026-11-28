-- =====================================================================
-- Migración: categorías de la acción, tipos de ticket de TI (tarea,
-- actualizaciones, levantamiento, instrucción, formateos, bajas, altas)
-- y varios equipos por ticket.
-- Aplicar DESPUÉS de 2026-12-02_cobertura_agentes.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Categoría de la acción (la elige quien crea el ticket; el Admin TI la edita).
CREATE TABLE categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL UNIQUE,
    descripcion VARCHAR(500) NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

ALTER TABLE tickets
    MODIFY COLUMN tipo_ticket ENUM('incidencia', 'solicitud', 'actualizacion_ubicacion', 'garantia', 'formateo', 'tarea', 'actualizaciones', 'levantamiento', 'instruccion', 'formateos', 'bajas', 'altas') NOT NULL DEFAULT 'incidencia',
    ADD COLUMN categoria_id INT UNSIGNED NULL AFTER folio_remedy,
    ADD CONSTRAINT fk_ticket_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL;

-- Varios equipos por ticket (tickets de TI: tareas, levantamientos, altas, bajas…).
CREATE TABLE ticket_equipos (
    ticket_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, equipo_id),
    CONSTRAINT fk_tequ_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tequ_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
