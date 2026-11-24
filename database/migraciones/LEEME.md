# Migraciones de base de datos

Úsalas solo para **actualizar una instalación que ya funciona**. Una instalación
nueva no las necesita: el asistente `/instalacion` importa `database/schema.sql`
completo.

| Archivo | Lleva de | A | Qué agrega |
|---|---|---|---|
| `2026-11-23_fase5.sql` | Fase 4 | Fase 5 | Rol supervisor, ubicaciones físicas, asignaciones y préstamos, avisos, tareas, verificación de correo y contraseña de admin local |
| `2026-11-23_geca_componentes.sql` | Fase 5 | GECA por componente | Columnas del reporte "Equipos entregados con estatus": tipo de componente, orden, lote, referencia, entidad, municipio, tipo de asignación, resguardo anterior y ficha del jefe |
| `2026-11-24_avisos_imagen.sql` | GECA por componente | Noticias con imagen | Imagen opcional en los avisos (carrusel de la pantalla de entrada) |

## Cómo aplicar una migración (phpMyAdmin en cPanel)

1. **Respalda la base:** phpMyAdmin › selecciona la base › *Exportar* › *Rápido* › *SQL* › *Continuar*.
2. phpMyAdmin › selecciona la base › *Importar* › elige el archivo `.sql` › *Continuar*.
3. Debe terminar con “Importación ejecutada exitosamente”.

Se aplican **en el orden de la tabla**. Cada migración se ejecuta **una sola vez**. Si la vuelves a importar, se detiene en el
primer `CREATE TABLE` (o "Duplicate column name") porque ya estaba aplicada; no daña los datos.

Después de la Fase 5, **todas las cuentas existentes deben verificar su correo** en su
siguiente ingreso. Revisa antes que el envío de correo funcione (Gestión › Usuarios ›
*Enviarme un correo de prueba*).
