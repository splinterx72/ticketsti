# CLAUDE.md — Mesa de Ayuda TI y Control de Resguardos (GECA)

Sistema web ligero de tickets de soporte técnico integrado con el inventario oficial de resguardos (**GECA**). Los trabajadores se registran validando su **ficha**, levantan tickets asociados a su equipo principal, monitores o incidencias generales; el sistema detecta discrepancias de ubicación física frente al resguardo oficial y gestiona garantías de hardware.

---

## 1. Stack y restricciones de despliegue (hosting compartido cPanel — NEUBOX)

* **Servidor:** Apache con `mod_rewrite` en hosting compartido cPanel. **Sin Docker, sin Nginx, sin SSH, sin Composer en el servidor.**
* **Backend:** PHP 8.3 / 8.4, `declare(strict_types=1);` en todos los archivos, MVC ligero propio (sin frameworks), PDO con sentencias preparadas reales (`ATTR_EMULATE_PREPARES = false`).
* **Base de datos:** MySQL / MariaDB de cPanel (`InnoDB`, `utf8mb4_unicode_ci`). La BD se crea desde el asistente de cPanel con prefijo (`usuario_bd`); por eso `database/schema.sql` **no** lleva `CREATE DATABASE` ni `USE`.
* **Zona horaria:** `America/Mexico_City` en PHP y en la sesión MySQL (con respaldo a desfase numérico porque el hosting no suele tener cargadas las tablas de zonas horarias).
* **Frontend:** HTML5 + Bootstrap 5.3 e íconos de Bootstrap vía CDN (jsDelivr, versión fija + SRI) + JavaScript vanilla.
* **Archivos GECA:** `.csv` nativo con `fgetcsv` (delimitador `,`/`;`, UTF-8 o ANSI de Excel) y `.xlsx` con `src/Libs/SimpleXLSX.php` (lector en streaming con `ZipArchive` + `XMLReader`). **No usar PhpSpreadsheet** (consumo de RAM excesivo en hosting compartido).
* **Autoload:** `src/Core/Autoloader.php` (PSR-4 nativo, `App\` → `src/`).
* **Tipografía y tema:** Roboto (texto) y Roboto Condensed (títulos, navegación, tablas, botones) desde Google Fonts; paleta en `assets/css/theme.css` (primario `#0b5d73`, acento decorativo `#14a39a`, texto verde azulado `#0e7f78`). Sin identidad gráfica de Pemex.
* **Tareas programadas:** `bin/cron.php` (Tarea Cron diaria de cPanel); respaldo automático en la primera petición del día (`Recordatorios::ejecutarSiPendiente`).
* **Pruebas:** `php tests/run.php [Filtro]` (arnés propio sin Composer; requiere MySQL/MariaDB local con la base `usr_mesa_test`). `tests/` y `docs/` no se empaquetan (`.gitattributes export-ignore`).

### Estructura

```
/                      ← raíz del dominio/subdominio (public_html o tickets.dominio.com)
├── .htaccess          ← enrutamiento a index.php + bloqueo de carpetas internas
├── .user.ini          ← límites PHP para PHP-FPM (upload, memoria, tiempo)
├── index.php          ← Front Controller y tabla de rutas
├── assets/            ← CSS/JS públicos (único directorio servible además de index.php)
├── config/            ← config.example.php (versionado) y config.php (NO versionado)  [bloqueado]
├── database/          ← schema.sql para phpMyAdmin                                   [bloqueado]
├── bin/               ← cron.php (solo consola)                                       [bloqueado]
├── src/               ← Config, Core, Controllers, Services, Libs, Views              [bloqueado]
└── storage/           ← uploads temporales, logs, sesiones, tareas (evidencias)       [bloqueado]
```

### Convenciones de seguridad (obligatorias)

* Todo formulario POST lleva `<?= csrf_field() ?>` y el controlador llama `$this->verifyCsrf()`.
* Toda salida en vistas pasa por `e()` (`htmlspecialchars` con `ENT_QUOTES | ENT_SUBSTITUTE`).
* Contraseñas solo con `password_hash()` / `password_verify()`.
* Nada de SQL concatenado con datos del usuario; siempre `prepare()` + `execute()`.
* Acceso por rol con `$this->requireRole([...])`; roles en `Core/Roles`: `usuario`, `agente_ti`, `supervisor`, `admin_ti` (`Roles::TI`, `Roles::SUPERVISION`).
* Alcance por instalación con `Services/Alcance` (`instalacionesDe`, `puedeVerTicket`, `filtroTicketsSql`): nadie ve tickets ni tareas fuera de sus instalaciones vigentes (salvo `admin_ti`).
* `requireLogin()` además bloquea: correo sin verificar → `/verificar-correo`; contraseña de admin local a ≤ 5 días → `/admin-local`.
* Archivos de tareas: `Services/ArchivoService` (lista blanca de extensiones + MIME real, 10 MB, sin código PHP, nombre aleatorio, descarga solo con permiso).
* Los archivos subidos se guardan en `storage/uploads` con nombre aleatorio y se borran al terminar.
* No usar códigos HTTP no estándar (p. ej. 419): Apache los convierte en 500.

---

## 2. Estado por fases

* **Fase 1 (hecha):** estructura base, `.htaccess`, configuración, esquema, Singleton PDO, autoloader, Front Controller, sesión endurecida, CSRF, login/logout, asistente `/instalar` para el primer `admin_ti`.
* **Fase 2 (hecha):** motor de sincronización GECA (`GecaSyncService`), lectores CSV/XLSX, módulo `/geca` (carga, historial, detalle, plantilla).
* **Instalador (hecho):** asistente web `/instalacion` (`InstallerController` + `Services/Installer`). Sin `config/config.php` el Front Controller entra en *modo instalación* y solo responde el asistente. Cualquier cambio a `database/schema.sql` se instala automáticamente con él.
* **Fase 3 (hecha):**
  * Auto-registro por ficha (`RegistroController`, `/registro`).
  * Portal del trabajador: `/tickets`, `/tickets/nuevo`, `/tickets/{id}` y comentarios; equipo principal en el inicio.
  * Bandeja TI: `/ti/tickets` con filtros, "Tomar", gestión de estado, prioridad, departamento y agente, y notas internas.
  * Las reglas C y D viven en `Services/TicketService`. Folio `TK-AAAA-NNNNNN`.
  * El ticket `actualizacion_ubicacion` se genera una sola vez por equipo mientras siga abierto; los siguientes reportes se agregan como nota interna.
  * Enrutamiento automático por tipo: incidencia y solicitud → Soporte Técnico en Sitio; garantía → Garantías y Proveedores; actualización de ubicación → Control de Resguardos GECA.
  * Los cambios hechos por TI quedan en la bitácora del ticket y el solicitante los ve. Las notas internas solo las ve TI.
  * Política de contraseñas: `Core/PasswordPolicy` (10 caracteres para trabajadores, 12 para el admin del instalador).
* **Fase 5 (hecha):** supervisores por instalación (titular/suplencia con fechas), agentes asignados y préstamos temporales; ubicaciones físicas Instalación → Ubicación → Nivel → Ala con indicaciones del lugar y referencia; regla D por instalación (equivalencias inmueble GECA → instalación); registro con correo de empresa verificado (enlace 24 h) y aviso al supervisor; contraseña de admin local (avisos desde 10 días, bloqueo total a ≤ 5); avisos con vigencia; tareas con folio `TA-AAAA-NNNNNN`, entregas y evidencias por agente; encabezado con ubicación y extensión; tema Roboto. Diseño en `docs/superpowers/specs/2026-11-23-supervisores-ubicaciones-tareas-design.md`; migración en `database/migraciones/2026-11-23_fase5.sql`.
* **Fase 4 (hecha):**
  * Garantías (`GarantiaService`, `/ti/garantias`): alta por serie de CPU o de monitor y validación del monitor casado. Las vigentes con fecha pasada se marcan como vencidas al consultarlas (sin cron). "Por vencer" = 60 días.
  * Inventario TI (`/ti/equipos`, `/ti/equipos/{id}`): ficha GECA, ubicaciones, garantías e historial de tickets. El detalle del ticket muestra la garantía que cubre el componente reportado.
  * Avisos por correo (`Services/Mail`): `Mailer` con drivers `off`, `mail` o `smtp` (SSL/STARTTLS nativo) y `Notifier`, que encola y envía al terminar la petición (`fastcgi_finish_request`). Un fallo de correo solo se registra en el log. Las notas internas nunca se notifican.
  * Reportes y tablero de agentes (`/ti/reportes`, exportación CSV con protección contra fórmulas).
  * Usuarios (`/ti/usuarios`, solo `admin_ti`): rol, activo, contraseña temporal y correo de prueba. Un admin no puede cambiarse a sí mismo y debe quedar al menos un `admin_ti` activo. Solo cuentas activadas pueden recibir rol de TI.
  * Mi cuenta (`/cuenta`): contacto, ubicación habitual y cambio de contraseña.
  * Confirmaciones con `data-confirm` (nunca JS en línea con datos).

---

## 3. Reglas de negocio críticas

### A. Sincronización Semanal del GECA (Cada Lunes — Estrategia UPSERT)
* **Prohibido hacer `TRUNCATE` o borrar registros** de `usuarios` y `equipos`, ya que se perderían las cuentas activadas, contraseñas, historial de tickets, ubicaciones reales y garantías.
* **Llaves naturales:** `ficha` para usuarios y `serie` para equipos.
* **Flujo de importación:**
  1. Se crea un registro de auditoría en `cargas_geca`.
  2. Se actualiza todo el inventario actual a `vigente_en_geca = 0`.
  3. Por cada fila del archivo GECA (30 columnas):
     * Se inserta o recupera el ID en `ubicaciones` (`eps`, `subdireccion`, `gerencia`, `zona`, `sector`, `inmueble`).
     * Si la `ficha` no existe en `usuarios`, se inserta con `registrado = 0`. Si ya existe, se actualizan `nombre` y `jefe_nombre` sin tocar su contraseña, correo ni rol.
     * Si la `serie` no existe en `equipos`, se inserta con `vigente_en_geca = 1` y `ubicacion_real_id = ubicacion_geca_id`. Si ya existe, se actualizan los campos del GECA, `usuario_id`, `ubicacion_geca_id` y `vigente_en_geca = 1`, **preservando intactos** `ubicacion_real_id`, `es_principal`, `garantias` y `tickets`.

### B. Registro y Autenticación de Usuarios
* **Auto-registro por Ficha:** El trabajador ingresa su número de `ficha`. El sistema busca en `usuarios` (`registrado = 0`).
* Si existe, muestra su nombre y jefe para confirmación; el usuario captura **obligatoriamente** su correo **de la empresa** (dominios en `registro.dominios_permitidos`), `telefono_ext`, contraseña y su **ubicación física actual** (`habitual_*`).
* La cuenta queda en `registrado = 0` hasta abrir el enlace enviado a su correo (24 h, un solo uso, se guarda solo el hash). Al verificar: `registrado = 1`, `email_verificado_en` y aviso a los supervisores de su instalación. Las cuentas sin `email_verificado_en` deben verificar al entrar; el admin puede marcarlo a mano.
* **Roles:** `usuario` (solicitante), `agente_ti` (técnico que atiende y sube GECA), `supervisor` (agente con funciones de supervisión de sus instalaciones), `admin_ti` (gestión total).
* **Contraseña de admin local (TI):** vence 3 meses calendario después de `admin_local_cambiado_en`; correo y aviso diario desde 10 días antes (agente + supervisores de su instalación base; supervisor/admin → admin TI); **bloqueo total** a 5 días o menos, vencida o sin fecha, hasta registrar el cambio en `/admin-local`.

### C. Creación de Tickets y Hostname
* Al abrir el formulario de nuevo ticket, el sistema carga los equipos asignados al usuario ordenados por `es_principal DESC` (preseleccionando su equipo principal, permitiendo cambiar a otro equipo, a un monitor casado o a **"Sin equipo / Incidencia general"**).
* **Hostname manual:** El usuario o técnico puede capturar el hostname completo (prefijo de 5 caracteres que inicia con `PM...`, ej. `PMPPP` + `serie`) en el campo `hostname_reportado`.
* **Componente afectado:** Permite especificar si la falla es en `equipo`, `monitor_1`, `monitor_2` o `general`.

### D. Control de Ubicaciones (Oficial GECA vs. Real Física)
* Hay dos mundos: la ubicación **GECA** (tabla `ubicaciones`, la escribe la carga semanal) y la **física** de la app: `instalaciones` → `ubicaciones_fisicas` (edificio, nave, taller, área…) → nivel (`niveles` habilitados; si ninguno, "Único") → ala (norte/sur/oriente/poniente habilitadas; si ninguna, "N/A"), con **indicaciones del lugar** (supervisor) y **referencia** (trabajador). Los supervisores capturan las ubicaciones de sus instalaciones sobre la plantilla general del admin.
* Al crear un ticket, el usuario indica la ubicación física (obligatoria si existe catálogo) → `tickets.instalacion_id` + `reportada_*` + `referencia`. Con equipo, `equipos.real_*` se actualiza siempre con lo reportado.
* **Discrepancia por instalación:** si la instalación reportada ≠ la instalación equivalente del inmueble GECA del equipo (`geca_inmueble_instalacion`), se marca `discrepancia_ubicacion = 1` y se genera (una vez por equipo mientras siga abierto) el ticket `actualizacion_ubicacion`, que hereda la instalación reportada. Inmueble sin equivalencia = sin discrepancia (se lista como pendiente al admin).
* Las columnas GECA antiguas `ubicacion_real_id`/`ubicacion_reportada_id`/`ubicacion_habitual_id` se conservan solo como historial.

### D2. Supervisión por instalación
* `supervisor_instalaciones` (titular o suplencia con fechas) y `agente_instalaciones` (base o préstamo con fecha fin obligatoria). Vigente = `(desde IS NULL OR desde <= hoy) AND (hasta IS NULL OR hasta >= hoy)`. El admin asigna supervisores; el supervisor asigna agentes a sus instalaciones y presta a los suyos.
* Ticket nuevo → correo a los supervisores vigentes de su instalación + `mail.mesa_institucional` (Remedy) + `notify_new_tickets_to`.
* **Avisos** (`avisos`): solo supervisores/admin; vigencia inicio–fin; alcance "todos" (también en el login) o instalaciones propias.
* **Tareas** (`tareas`, `tarea_asignaciones`, `tarea_archivos`): una tarea, una entrega por agente con sus evidencias; estados asignada → en_proceso → entregada → aceptada | devuelta.

### E. Gestión de Garantías
* Módulo vinculado a `equipos` que permite registrar pólizas/contratos indicando si cubren el `equipo`, `monitor_1` o `monitor_2`, con fechas de vigencia, proveedor y estado (`vigente`, `en_reclamacion`, `vencida`).

### F. Formato del archivo GECA (30 encabezados)

`folio, fecha, estado_resguardo, numero_contrato, nombre, ficha, jefe_nombre, eps, subdireccion, gerencia, zona, sector, inmueble, serie, estado_inventario, marca, modelo, perfil, usuario_fecha_aceptacion, ti_fecha_validacion, fecha_baja, fecha_carga_resguardo, fuera_consola, motivo_baja, serie_monitor1, marca_monitor1, modelo_monitor1, serie_monitor2, marca_monitor2, modelo_monitor2`

* Los encabezados se comparan sin acentos, mayúsculas, espacios ni signos (`"Número de Serie"` = `serie`, `"Jefe"` = `jefe_nombre`). Los alias aceptados viven en `GecaSyncService::ALIASES`.
* Fechas aceptadas: serial de Excel, `dd/mm/aaaa [hh:mm[:ss]] [a. m./p. m.]`, `aaaa-mm-dd [hh:mm[:ss]]`.
* Toda la carga corre en **una transacción** con `GET_LOCK` para impedir cargas simultáneas; ante cualquier error se revierte completa.
* Las ubicaciones guardan `''` (no `NULL`) en `zona`/`sector` vacíos para que el índice único `uk_ubicacion_completa` no admita duplicados.

---

## 4. Esquema de base de datos

La fuente de verdad es **`database/schema.sql`** (26 tablas; lista completa en `Installer::TABLES`) con semillas de `estados`, `departamentos`, `niveles` y `ubicacion_tipos`. Cualquier cambio de esquema se hace ahí **y** como script incremental en `database/migraciones/` importable desde phpMyAdmin (ver `database/migraciones/LEEME.md`); `tests/SchemaTest.php` verifica que ambos coincidan.
