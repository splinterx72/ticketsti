-- =====================================================================
-- Migración: tareas como hoja de reportes. Meta de la tarea (equipos,
-- usuarios u otro, y cuántos por agente), reportes de avance con su
-- cantidad y evidencias, y aviso al supervisor al llegar al 100 %.
-- Aplicar DESPUÉS de 2026-12-03_categorias_tipos_ti.sql, UNA sola vez,
-- desde phpMyAdmin › tu base › Importar. No borra datos.
-- Si se detiene con "Duplicate column name" o "already exists", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE tareas
    ADD COLUMN meta_unidad ENUM('equipos', 'usuarios', 'otro') NULL AFTER evidencias_requeridas,
    ADD COLUMN meta_descripcion VARCHAR(60) NULL AFTER meta_unidad,
    ADD COLUMN meta_cantidad INT UNSIGNED NULL AFTER meta_descripcion;

ALTER TABLE tarea_asignaciones
    ADD COLUMN completa_avisada_en DATETIME NULL AFTER revisado_en;

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

ALTER TABLE tarea_archivos
    ADD COLUMN avance_id INT UNSIGNED NULL AFTER asignacion_id,
    ADD CONSTRAINT fk_tarch_avance FOREIGN KEY (avance_id) REFERENCES tarea_avances(id) ON DELETE CASCADE;
