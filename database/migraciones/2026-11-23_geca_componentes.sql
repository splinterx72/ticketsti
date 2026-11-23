-- =====================================================================
-- Migración: GECA por componente (reporte "Equipos entregados con estatus").
-- Aplicar DESPUÉS de 2026-11-23_fase5.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. Solo agrega columnas: no borra datos.
-- Si la vuelves a importar se detiene con "Duplicate column name": ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE usuarios
    ADD COLUMN jefe_ficha VARCHAR(20) NULL AFTER nombre;

ALTER TABLE equipos
    MODIFY COLUMN fuera_consola VARCHAR(120) NULL,
    ADD COLUMN tipo_componente VARCHAR(30) NULL AFTER motivo_baja,
    ADD COLUMN numero_orden VARCHAR(60) NULL AFTER tipo_componente,
    ADD COLUMN lote VARCHAR(60) NULL AFTER numero_orden,
    ADD COLUMN ubicacion_referencia VARCHAR(150) NULL AFTER lote,
    ADD COLUMN entidad_federativa VARCHAR(80) NULL AFTER ubicacion_referencia,
    ADD COLUMN municipio VARCHAR(100) NULL AFTER entidad_federativa,
    ADD COLUMN tipo_asignacion VARCHAR(80) NULL AFTER municipio,
    ADD COLUMN pr_folio VARCHAR(40) NULL AFTER tipo_asignacion,
    ADD COLUMN pr_fecha DATETIME NULL AFTER pr_folio,
    ADD COLUMN pr_estado_resguardo VARCHAR(60) NULL AFTER pr_fecha,
    ADD COLUMN pr_nombre VARCHAR(150) NULL AFTER pr_estado_resguardo,
    ADD COLUMN pr_ficha VARCHAR(20) NULL AFTER pr_nombre,
    ADD COLUMN pr_eps VARCHAR(80) NULL AFTER pr_ficha,
    ADD COLUMN pr_zona VARCHAR(100) NULL AFTER pr_eps;
