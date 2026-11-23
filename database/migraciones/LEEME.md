# Migraciones de base de datos

Úsalas solo para **actualizar una instalación que ya funciona**. Una instalación
nueva no las necesita: el asistente `/instalacion` importa `database/schema.sql`
completo.

| Archivo | Lleva de | A | Qué agrega |
|---|---|---|---|
| `2026-11-23_fase5.sql` | Fase 4 | Fase 5 | Rol supervisor, ubicaciones físicas, asignaciones y préstamos, avisos, tareas, verificación de correo y contraseña de admin local |

## Cómo aplicar una migración (phpMyAdmin en cPanel)

1. **Respalda la base:** phpMyAdmin › selecciona la base › *Exportar* › *Rápido* › *SQL* › *Continuar*.
2. phpMyAdmin › selecciona la base › *Importar* › elige el archivo `.sql` › *Continuar*.
3. Debe terminar con “Importación ejecutada exitosamente”.

Cada migración se ejecuta **una sola vez**. Si la vuelves a importar, se detiene en el
primer `CREATE TABLE` porque las tablas ya existen; no daña los datos.

Después de la Fase 5, **todas las cuentas existentes deben verificar su correo** en su
siguiente ingreso. Revisa antes que el envío de correo funcione (Gestión › Usuarios ›
*Enviarme un correo de prueba*).
