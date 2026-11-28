-- =====================================================================
-- Migración: lista de equipos (series) de una tarea; el agente los marca
-- como intervenidos y, si quiere, se genera un ticket por equipo.
-- Aplicar DESPUÉS de 2026-12-05_resguardo.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

-- Equipos de una tarea (meta "Equipos" con lista de series): el agente los
-- marca como intervenidos; con ticket, uno por equipo.
CREATE TABLE tarea_equipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tarea_id INT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NOT NULL,
    asignacion_id INT UNSIGNED NULL,
    atendido_por INT UNSIGNED NULL,
    atendido_en DATETIME NULL,
    ticket_id INT UNSIGNED NULL,
    UNIQUE KEY uk_tarea_equipo (tarea_id, equipo_id),
    CONSTRAINT fk_teq_tarea FOREIGN KEY (tarea_id) REFERENCES tareas(id) ON DELETE CASCADE,
    CONSTRAINT fk_teq_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    CONSTRAINT fk_teq_asignacion FOREIGN KEY (asignacion_id) REFERENCES tarea_asignaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_teq_atendio FOREIGN KEY (atendido_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_teq_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
