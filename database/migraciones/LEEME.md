# Migraciones de base de datos

Úsalas solo para **actualizar una instalación que ya funciona**. Una instalación
nueva no las necesita: el asistente `/instalacion` importa `database/schema.sql`
completo.

| Archivo | Lleva de | A | Qué agrega |
|---|---|---|---|
| `2026-11-23_fase5.sql` | Fase 4 | Fase 5 | Rol supervisor, ubicaciones físicas, asignaciones y préstamos, avisos, tareas, verificación de correo y contraseña de admin local |
| `2026-11-23_geca_componentes.sql` | Fase 5 | GECA por componente | Columnas del reporte "Equipos entregados con estatus": tipo de componente, orden, lote, referencia, entidad, municipio, tipo de asignación, resguardo anterior y ficha del jefe |
| `2026-11-24_avisos_imagen.sql` | GECA por componente | Noticias con imagen | Imagen opcional en los avisos (carrusel de la pantalla de entrada) |
| `2026-11-24_coordinadores_cuentas.sql` | Noticias con imagen | Coordinadores y cuentas | Tipo de cuenta (administración/prueba), rol Coordinador, zonas y coordinadores por zona |
| `2026-11-27_sla_avisos_adjuntos.sql` | Coordinadores y cuentas | SLA, lectura y adjuntos | Tiempos de atención por prioridad, primera atención de tickets, acuse de lectura de avisos y adjuntos en tickets |
| `2026-11-28_bitlocker_asignacion.sql` | SLA, lectura y adjuntos | BitLocker y asignación | Llaves de BitLocker por equipo (cifradas) y su bitácora, celular del usuario, disponibilidad y especialidades de agentes, modo de asignación por instalación y agente sugerido |
| `2026-11-29_modulos_correo.sql` | BitLocker y asignación | Módulos y correo | Módulos con control del Admin TI, correo de Remedy y copias desde la aplicación, Remedy por instalación, plantillas de correo y quién registra/modifica las garantías |
| `2026-11-30_delegaciones.sql` | Módulos y correo | Delegación | Personas autorizadas por un jefe para levantar tickets a su nombre y quién levantó cada ticket |
| `2026-12-01_formatos.sql` | Delegación | Formatos con firma | Formatos oficiales por año (logos y textos), documentos con folio y código de verificación, firmas del usuario, su jefe y TI, tipo de ticket "Solicitud de formateo", folio Remedy en el ticket, inventario del equipo y ciudad por instalación |
| `2026-12-02_cobertura_agentes.sql` | Formatos con firma | Cobertura por ubicación | Qué ubicaciones atiende cada agente y pausas por ubicación o por instalación que pone el supervisor |
| `2026-12-03_categorias_tipos_ti.sql` | Cobertura por ubicación | Categorías y tickets de TI | Categoría de la acción en cada ticket, tipos de ticket de TI (tarea, actualizaciones, levantamiento, instrucción, formateos, bajas, altas) y varios equipos por ticket |
| `2026-12-04_tareas_reportes.sql` | Categorías y tickets de TI | Tareas con reportes | Meta de la tarea (equipos, usuarios u otro), hoja de reportes de avance con cantidad y evidencias, % de avance y aviso al supervisor al 100 % |
| `2026-12-05_resguardo.sql` | Tareas con reportes | Resguardo de equipos | Recepción de equipos que dejan los trabajadores (componentes y accesorios, acta GECA escaneada), estados del equipo y disponibles para asignar; módulo `resguardo` |

## Cómo aplicar una migración (phpMyAdmin en cPanel)

1. **Respalda la base:** phpMyAdmin › selecciona la base › *Exportar* › *Rápido* › *SQL* › *Continuar*.
2. phpMyAdmin › selecciona la base › *Importar* › elige el archivo `.sql` › *Continuar*.
3. Debe terminar con “Importación ejecutada exitosamente”.

Se aplican **en el orden de la tabla**. Cada migración se ejecuta **una sola vez**. Si la vuelves a importar, se detiene en el
primer `CREATE TABLE` (o "Duplicate column name") porque ya estaba aplicada; no daña los datos.

Después de la Fase 5, **todas las cuentas existentes deben verificar su correo** en su
siguiente ingreso. Revisa antes que el envío de correo funcione (Gestión › Usuarios ›
*Enviarme un correo de prueba*).
