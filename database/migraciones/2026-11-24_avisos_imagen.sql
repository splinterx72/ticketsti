-- =====================================================================
-- Migración: imagen en los avisos (carrusel de noticias de la entrada).
-- Aplicar DESPUÉS de 2026-11-23_geca_componentes.sql, UNA sola vez, desde
-- phpMyAdmin › tu base › Importar. Solo agrega una columna.
-- Si dice "Duplicate column name", ya estaba aplicada.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE avisos
    ADD COLUMN imagen VARCHAR(255) NULL AFTER alcance;
