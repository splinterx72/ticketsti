# Diseño — Fase 5: Supervisores, ubicaciones físicas, avisos, tareas y contraseña de admin local

Fecha: 2026-11-23 · Proyecto: Mesa de Ayuda TI / Control de Resguardos GECA (`splinterx72/ticketsti`)

## 1. Intención y por qué

La app existe porque el sistema institucional de tickets (Remedy) falla mucho. Esta fase la
acerca a cómo opera realmente el área:

- **La responsabilidad es por instalación.** Un supervisor responde por una o varias
  instalaciones completas (con todas sus ubicaciones) y por los agentes asignados a ellas,
  no por todos.
- **La ubicación física real no se parece a la del GECA.** El GECA describe la estructura
  administrativa (EPS, subdirección, gerencia…). Para atender en sitio se necesita saber
  *a dónde ir*: Instalación → Ubicación → Nivel → Ala, más indicaciones de acceso.
  Ambas ubicaciones se conservan y se muestran.
- **La contraseña de admin local de cada agente no puede vencerse nunca.** Reactivarla
  tarda hasta 6 meses y deja al agente sin poder trabajar.
- **Los supervisores necesitan comunicar y delegar:** avisos con vigencia en el inicio y
  tareas con folio, fecha de entrega y evidencias.

## 2. Objetivos

1. Rol **Supervisor** con responsabilidad sobre una o más instalaciones (con fechas para
   suplencias).
2. Asignación de **agentes a instalaciones** y **préstamo temporal** a otra instalación.
3. **Catálogo de ubicaciones físicas** jerárquico, capturado por supervisores sobre una
   plantilla general, usado en tickets, equipos y usuarios.
4. **Avisos** en la página de inicio con vigencia, publicados solo por supervisores.
5. **Recordatorios y bloqueo** por la contraseña de admin local de cada agente.
6. **Tareas** del supervisor a uno o varios agentes, con folio, fecha de entrega y
   evidencias por agente.
7. **Correo de ticket nuevo** a los supervisores de la instalación y a la mesa de ayuda
   institucional (`mesadeayuda@pemex.com`, configurable) para que entre a Remedy.
8. **Diseño visual:** Roboto y Roboto Condensed en todo el sistema, paleta moderna.

## 3. No-objetivos

- Integración directa con Remedy por API (solo correo).
- Guardar la contraseña de admin local (solo fechas).
- Cambiar la carga GECA ni la tabla `ubicaciones` del GECA.
- Chat o notificaciones push.

## 4. Anti-objetivos (sería un fracaso aunque “funcione”)

- Que una contraseña de admin local llegue a vencerse sin que el agente haya sido
  bloqueado al menos 5 días antes.
- Que la carga semanal del GECA borre o sobrescriba ubicaciones físicas capturadas.
- Que un agente vea tickets o tareas de instalaciones que no le corresponden.
- Que las evidencias sean descargables sin sesión o por alguien ajeno a la tarea.
- Usar logotipos o identidad gráfica de Pemex.

## 5. Restricciones

Las de `CLAUDE.md`: hosting compartido cPanel (NEUBOX), Apache + `.htaccess`, PHP 8.3+
sin Composer ni frameworks, MySQL/MariaDB, sin SSH, PDO con prepares reales, CSRF, `e()`,
reglas UPSERT del GECA. Además:

- Todo cambio de esquema va en `database/schema.sql` **y** en un script incremental
  `database/migraciones/2026-11-23_fase5.sql` importable en phpMyAdmin sobre una
  instalación existente, sin borrar datos.
- Fuentes desde Google Fonts (permitido en el CSP de facto del proyecto; no hay CSP estricto).
- Los archivos de tareas consumen la cuota de disco del plan; no se borran automáticamente.

## 6. Enfoques considerados (elegidos por el usuario: 1A, 2A, 3A)

| Tema | A (elegido) | B (descartado) |
|---|---|---|
| 1. Ubicaciones físicas | Tablas nuevas (instalaciones → ubicaciones → niveles/alas habilitados) y columnas nuevas en tickets/equipos/usuarios, junto a las del GECA. | Reutilizar `ubicaciones` del GECA: la carga semanal la reescribe y mezcla los dos mundos. |
| 2. Recordatorios a tiempo | Tarea Cron diaria de cPanel + respaldo: revisión en la primera petición del día. | Solo revisar cuando alguien entra: un fin de semana sin accesos retrasa avisos. |
| 3. Tareas a varios agentes | Una tarea (un folio) con una entrega por agente, cada una con estado y evidencias propias; vista de avance. | Una tarea independiente por agente: se pierde la vista de grupo. |

## 7. Lo decidido con el usuario

### 7.1 Ubicaciones físicas

Jerarquía y ejemplos reales:

| Instalación | Ubicación | Nivel | Ala | Indicaciones del lugar |
|---|---|---|---|---|
| Zona Industrial Km 4.5 | Edificio Holkanaab | 1 | Norte | — |
| Zona Industrial Laguna Azul | Área de Carga | Único | N/A | Es necesario contar con botas de trabajo y todo el equipo de seguridad |

- **Instalación** = el complejo (“Zona Industrial…”).
- **Ubicación** = edificio, nave, taller, área, edificio menor, etc. Nombre libre; **tipo**
  opcional de una plantilla (Edificio, Nave, Taller, Área, Caseta, Otro) para filtrar e icono.
- **Nivel**: si la ubicación tiene pisos habilitados, se elige uno; si no, queda **“Único”**.
- **Ala**: Norte, Sur, Oriente, Poniente si están habilitadas; si no, **“N/A”**.
- Nivel y Ala **siempre se muestran** (con “Único” / “N/A”), para que el registro se lea completo.
- **Dos textos distintos:**
  - **Indicaciones del lugar**: las captura el supervisor por ubicación (edificio/área);
    el agente las ve en cada ticket de ese lugar.
  - **Referencia**: texto libre del trabajador en su ticket (“junto a la sala de juntas 2”).
- **Plantilla general** (admin TI): tipos de ubicación y catálogo de niveles
  (Único, Sótano, PB, 1…N, Mezanine…). **Cada supervisor**, en sus instalaciones, da de
  alta las ubicaciones y habilita qué niveles y alas aplican.
- Se usa en: **ticket** (ubicación reportada), **equipo** (ubicación real) y **usuario**
  (ubicación habitual). La del GECA se conserva y se muestra junto.

### 7.2 Discrepancia GECA vs. física (regla D adaptada)

- Cada **inmueble del GECA** se asocia a una **instalación física** (tabla de equivalencias
  que mantiene el admin TI; se puede sugerir el supervisor).
- Hay discrepancia si la **instalación** reportada ≠ la instalación equivalente del inmueble
  GECA del equipo. Si el inmueble aún no tiene equivalencia, no se marca discrepancia y se
  lista como **“pendiente de asignar”** para el admin.
- Con discrepancia: se actualiza la ubicación física real del equipo, se marca el ticket y se
  genera (una vez por equipo mientras siga abierto) el ticket `actualizacion_ubicacion`,
  como hoy.

### 7.3 Supervisores, agentes y préstamos

- Nuevo rol `supervisor` (además de `usuario`, `agente_ti`, `admin_ti`).
- **Supervisor ↔ instalaciones**: una o más; cada asignación con `desde`/`hasta` opcionales
  (titular o suplencia por vacaciones/ausencia). La responsabilidad es por instalación
  completa.
- **Agente ↔ instalaciones**: asignación base y **préstamo** con fechas. Al vencer el
  préstamo, el agente regresa solo (las consultas filtran por fecha vigente).
- Los préstamos los registra un supervisor sobre sus propios agentes hacia otra instalación.
  Durante el préstamo, el agente atiende tickets de la instalación que lo recibe y ese
  supervisor puede mandarle tareas; su supervisor de origen lo sigue viendo.
- **Visibilidad de tickets:** el agente ve los de sus instalaciones vigentes (incluidas las
  de préstamo) más los que tenga asignados; el supervisor, los de sus instalaciones; el
  admin, todos.

### 7.4 Avisos en el inicio

- Solo **supervisores** publican (el admin TI también puede, como superusuario).
- Campos: título, mensaje, importancia (informativo / importante / urgente), inicio y fin
  (fecha y hora). Fuera de su periodo no se muestran.
- Alcance: **todos** o **solo sus instalaciones**. Se muestran en el inicio; los de alcance
  “todos” también en la pantalla de entrada (login).

### 7.5 Contraseña de admin local (una por agente)

- Se guarda solo la **fecha de cambio**; el vencimiento = fecha + 3 meses calendario.
- **Avisos** (correo + aviso en pantalla) al agente **y a su supervisor** desde **10 días**
  antes; en pantalla, visible a diario de 10 a 6 días.
- **≤ 5 días o vencida: bloqueo total.** El agente solo ve la pantalla “Registra el cambio
  de tu contraseña de admin local” y “Cerrar sesión”, hasta registrar la nueva fecha.
  Correo diario al agente y a su supervisor mientras siga bloqueado.
- Agente sin fecha registrada = bloqueado hasta registrarla (primer ingreso).
- **Semáforo para el supervisor**: verde (> 10 días), amarillo (6–10), rojo (≤ 5 / vencida).
- Envío puntual con **Tarea Cron de cPanel** diaria (`php bin/cron.php`), con respaldo:
  la primera petición del día ejecuta la revisión si el cron no corrió.

### 7.6 Tareas del supervisor

- Folio `TA-AAAA-NNNNNN`.
- Campos: título, instrucciones, fecha/hora de entrega, prioridad, archivos de referencia
  opcionales y **evidencias requeridas** (lista: foto / documento / archivo + descripción).
- Se asigna a **uno o varios agentes** de sus instalaciones (incluidos los prestados).
  **Cada agente entrega sus propias evidencias.**
- Estados por agente: Asignada → En proceso → Entregada → **Aceptada** o **Devuelta**
  (con comentario; vuelve a En proceso). “Vencida” se marca si pasó la fecha sin aceptar.
- El supervisor ve el avance (“3 de 5 entregaron”).
- Archivos: PDF, imágenes (JPG/PNG/WEBP), Word, Excel y ZIP, máx. 10 MB por archivo;
  guardados en `storage/tareas/` con nombre aleatorio; descarga solo por el supervisor de
  la tarea, el admin y el agente dueño de esa entrega (los de referencia, todos los
  agentes de la tarea). No se borran automáticamente.
- Correos: al asignar, al entregar, al devolver/aceptar y un día antes de la fecha de entrega.

### 7.7 Correo de ticket nuevo

Destinatarios: supervisores vigentes de la instalación reportada + dirección institucional
configurable (`mail.mesa_institucional`, por defecto `mesadeayuda@pemex.com`) + la lista
existente `notify_new_tickets_to`. El asunto conserva `[TK-AAAA-NNNNNN]`.
Riesgo documentado: los servidores institucionales pueden rechazar correo de un hosting
externo; se recomienda SMTP con cuenta propia y verificar con el correo de prueba.

### 7.8 Diseño visual

- Roboto (texto) y Roboto Condensed (títulos, tablas, insignias, navegación) vía Google Fonts.
- Paleta moderna: azul petróleo / verde azulado como primario, superficies claras, sombras
  suaves, bordes redondeados; variables CSS que sobrescriben Bootstrap. Sin identidad Pemex.

## 8. Modelo de datos (propuesto)

Tablas nuevas:

- `instalaciones` (id, nombre UNIQUE, activo)
- `ubicacion_tipos` (id, nombre UNIQUE, activo) — plantilla
- `niveles` (id, nombre UNIQUE, orden, activo) — plantilla; incluye “Único”
- `ubicaciones_fisicas` (id, instalacion_id FK, tipo_id FK NULL, nombre, indicaciones TEXT NULL,
  activo; UNIQUE(instalacion_id, nombre))
- `ubicacion_fisica_niveles` (ubicacion_fisica_id, nivel_id) — niveles habilitados
- `ubicacion_fisica_alas` (ubicacion_fisica_id, ala ENUM('norte','sur','oriente','poniente'))
- `geca_inmueble_instalacion` (inmueble VARCHAR(150) PK, instalacion_id FK)
- `supervisor_instalaciones` (id, supervisor_id, instalacion_id, tipo ENUM('titular','suplencia'),
  desde DATE NULL, hasta DATE NULL, creado_por, creado_en)
- `agente_instalaciones` (id, agente_id, instalacion_id, tipo ENUM('base','prestamo'),
  desde DATE NULL, hasta DATE NULL, creado_por, creado_en)
- `avisos` (id, titulo, mensaje, importancia ENUM, inicia_en, termina_en,
  alcance ENUM('todos','instalaciones'), autor_id, creado_en) + `aviso_instalaciones`
- `tareas` (id, folio UNIQUE, supervisor_id, titulo, instrucciones, prioridad, entrega_en,
  evidencias_requeridas JSON/TEXT, creado_en)
- `tarea_asignaciones` (id, tarea_id, agente_id, estado ENUM, comentario_entrega,
  entregado_en, comentario_revision, revisado_en, UNIQUE(tarea_id, agente_id))
- `tarea_archivos` (id, tarea_id, asignacion_id NULL, nombre_original, ruta, mime, tamano,
  subido_por, creado_en)
- `recordatorios_enviados` (usuario_id/tarea_asignacion_id, tipo, clave, enviado_en; UNIQUE)
  para no duplicar correos
- `sistema_estado` (clave PK, valor) — p. ej. última ejecución del cron diario

Columnas nuevas (todas NULL, se conservan las del GECA):

- `usuarios.rol` + `'supervisor'`; `usuarios.admin_local_cambiado_en DATE NULL`;
  ubicación habitual física: `habitual_ubicacion_fisica_id`, `habitual_nivel_id`, `habitual_ala`.
- `equipos`: `real_ubicacion_fisica_id`, `real_nivel_id`, `real_ala`.
- `tickets`: `reportada_ubicacion_fisica_id`, `reportada_nivel_id`, `reportada_ala`,
  `referencia VARCHAR(255) NULL`, `instalacion_id` (desnormalizada para filtros y visibilidad).

`ala` se guarda como ENUM('na','norte','sur','oriente','poniente'); la instalación se
obtiene de la ubicación física.

## 8.1 Decisiones del diseñador (propuestas tras la revisión; el usuario puede corregirlas)

- **Tickets sin instalación.** La ubicación física es obligatoria en ticket nuevo cuando
  la instalación del trabajador ya tiene ubicaciones capturadas. Si el catálogo aún está
  vacío, el ticket se acepta sin ubicación física. Los tickets sin `instalacion_id` (todos
  los existentes y los nuevos sin catálogo) los ven **todos los agentes, supervisores y
  el admin**, en una vista "Sin instalación". Así nadie pierde la cola actual al desplegar.
- **Qué supervisor recibe los avisos de contraseña.** Los supervisores vigentes (titular
  y suplente) de las instalaciones **base** del agente. El supervisor que lo tiene
  prestado ve al agente en su semáforo, pero no recibe los correos.
- **Audiencia de un aviso "solo mis instalaciones".** Los agentes y supervisores asignados
  ahí, más los trabajadores cuya ubicación habitual física está en esa instalación. La
  instalación también se deduce del equipo principal: primero su ubicación física real y,
  si no tiene, la equivalencia de su inmueble GECA. Quien no tenga instalación conocida
  solo ve los avisos para "todos".
- **Quién mantiene las asignaciones.**
  - El admin TI asigna supervisores a instalaciones, titulares y suplentes.
  - Cada supervisor asigna agentes a sus propias instalaciones (base) y registra préstamos
    de sus agentes hacia otras instalaciones.
  - Durante un préstamo, el agente **sigue viendo** su instalación base y además la que lo
    recibe.
- **Formulario de ticket.**
  - El selector de ubicación GECA se reemplaza por el selector físico.
  - La ubicación GECA del equipo se muestra solo como lectura.
  - La ubicación física real del equipo se actualiza **siempre** con la reportada (como
    hoy), haya o no discrepancia.
  - La discrepancia se evalúa por instalación (sección 7.2).

## 9. Lo que queda al builder

- Pantallas exactas y navegación de catálogos, asignaciones, avisos y tareas.
- Nombres finales de clases/rutas y reparto en controladores/servicios.
- Cómo se migran los registros existentes que apuntan a ubicaciones GECA en
  `ubicacion_real_id` / `ubicacion_reportada_id` / `ubicacion_habitual_id` (se conservan;
  las columnas físicas inician vacías y se llenan con el uso).
- Ajuste del instalador para que reconozca la lista ampliada de tablas.
- Detalle de la paleta y componentes visuales dentro de lo descrito.
- Pruebas: mismas prácticas que fases anteriores (arnés PHP + pruebas HTTP con Apache).
