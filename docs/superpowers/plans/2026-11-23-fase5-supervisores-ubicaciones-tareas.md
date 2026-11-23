# Fase 5 — Supervisores, ubicaciones físicas, avisos, tareas y contraseña de admin local · Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar el rol supervisor con responsabilidad por instalación, el catálogo de ubicaciones físicas, el registro con correo verificado, el control de la contraseña de admin local con bloqueo, los avisos, las tareas con evidencias y el nuevo aspecto visual. Se entregan solo los archivos cambiados más un script SQL para phpMyAdmin.

**Architecture:** MVC propio existente (Front Controller `index.php`, controladores en `src/Controllers`, reglas en `src/Services`, vistas PHP). Un servicio de alcance (`Alcance`) responde “qué instalaciones ve este usuario hoy”. Todo módulo filtra con él. Las ubicaciones físicas viven en tablas nuevas, junto a las del GECA. Las tareas programadas corren con `bin/cron.php` (Cron de cPanel) y, como respaldo, en la primera petición del día.

**Tech Stack:** PHP 8.3 sin Composer, MySQL/MariaDB (PDO), Apache + `.htaccess`, Bootstrap 5.3, Roboto y Roboto Condensed (Google Fonts).

**Spec:** `docs/superpowers/specs/2026-11-23-supervisores-ubicaciones-tareas-design.md`

## Global Constraints

- `declare(strict_types=1);` en todo archivo PHP; PDO con `prepare()` + `execute()`; sin SQL concatenado con datos del usuario.
- Todo POST lleva `csrf_field()` y el controlador llama `verifyCsrf()`; toda salida pasa por `e()`.
- Sin códigos HTTP no estándar (usar 403/404/400).
- Esquema: actualizar `database/schema.sql` **y** crear `database/migraciones/2026-11-23_fase5.sql` (sin `CREATE DATABASE`/`USE`, se ejecuta una vez en phpMyAdmin, no borra datos).
- La carga GECA (`GecaSyncService`) no se modifica.
- Roles: `usuario`, `agente_ti`, `supervisor`, `admin_ti`. El supervisor es un agente con funciones adicionales.
- Contraseña de admin local: vence a los **3 meses calendario** de la fecha de cambio. Avisos a **10 días o menos**. **Bloqueo total a 5 días o menos, si está vencida o si no tiene fecha.**
- Semáforo: verde **más de 10** días, amarillo **6 a 10**, rojo **5 o menos**.
- Folio de tareas `TA-AAAA-NNNNNN`. Archivos: pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, zip; **10 MB** máximo por archivo; se guardan en `storage/tareas/`.
- Dominios de correo permitidos: config `registro.dominios_permitidos`, por defecto `['pemex.com']`. El enlace de verificación vale **24 h** y es de un solo uso.
- Correo institucional: config `mail.mesa_institucional`, por defecto `mesadeayuda@pemex.com`.
- Alas: `na`, `norte`, `sur`, `oriente`, `poniente` (etiquetas N/A, Norte, Sur, Oriente, Poniente). Nivel por defecto “Único”.
- Fuentes: Roboto (texto) y Roboto Condensed (títulos, navegación, tablas, insignias). Sin logotipos ni identidad Pemex.

## Review Focus

1. **Préstamo que vence:** con `hasta` = ayer, el agente ya no ve esa instalación; con `hasta` = hoy, todavía la ve (el límite es inclusivo). → prueba en Task 2.
2. **Umbrales del bloqueo:** si no tiene fecha → bloqueado; 5 días → bloqueado; 6 días → no bloqueado. Correr el cron dos veces el mismo día no duplica correos. → prueba en Task 6.
3. **Dominio del correo:** `Ana@PEMEX.COM` se acepta; `a@pemex.com.mx`, `a@x.pemex.com` y `a@pemex.co` se rechazan. → prueba en Task 5.
4. **Token de verificación:** un token usado o vencido se rechaza. Un token de otra cuenta no activa la mía. → prueba en Task 5.
5. **Evidencias de tareas:** otro agente de la misma tarea no descarga mis evidencias (404). Se rechazan `.php` y `foto.php.jpg` con contenido PHP. El nombre guardado nunca usa el nombre original. → prueba en Task 8.

---

### Task 1: Arnés de pruebas, esquema y migración

**Files:**
- Create: `tests/bootstrap.php`, `tests/run.php`, `tests/SchemaTest.php`, `tests/.htaccess`
- Create: `database/migraciones/2026-11-23_fase5.sql`, `database/migraciones/LEEME.md`
- Modify: `database/schema.sql`, `src/Services/Installer/Installer.php` (constante `TABLES`), `.gitattributes` (nuevo: `tests/ export-ignore`, `docs/ export-ignore`)

**Interfaces:**
- Produces: `tests/bootstrap.php` crea la BD `usr_mesa_test` desde cero y expone `test_pdo(): PDO`, `reset_db(): void`, `assert_eq(mixed $got, mixed $want, string $label): void`, `assert_true(bool $c, string $label): void`. `php tests/run.php [Filtro]` ejecuta todas las funciones `test_*` de `tests/*Test.php` y sale con código ≠ 0 si alguna falla.
- Produces (esquema): las tablas y columnas de la sección 8 del spec, con estos nombres exactos:
  - Tablas: `instalaciones`, `ubicacion_tipos`, `niveles`, `ubicaciones_fisicas`, `ubicacion_fisica_niveles`, `ubicacion_fisica_alas`, `geca_inmueble_instalacion`, `supervisor_instalaciones`, `agente_instalaciones`, `avisos`, `aviso_instalaciones`, `tareas`, `tarea_asignaciones`, `tarea_archivos`, `recordatorios_enviados`, `sistema_estado`, `verificaciones_email`.
  - Columnas en `usuarios`: `email_verificado_en`, `admin_local_cambiado_en`, `habitual_ubicacion_fisica_id`, `habitual_nivel_id`, `habitual_ala`, `habitual_referencia`.
  - Columnas en `equipos`: `real_ubicacion_fisica_id`, `real_nivel_id`, `real_ala`.
  - Columnas en `tickets`: `instalacion_id`, `reportada_ubicacion_fisica_id`, `reportada_nivel_id`, `reportada_ala`, `referencia`.
  - `usuarios.rol` agrega `'supervisor'`.
  - Semillas: `niveles` = Único, Sótano, PB, 1–10, Mezanine; `ubicacion_tipos` = Edificio, Nave, Taller, Área, Caseta, Otro.
  - Las cuentas ya registradas se marcan con `email_verificado_en = NULL`; esto fuerza la verificación (spec 7.6 bis).

- [ ] **Step 1: Write the failing test** `tests/SchemaTest.php`:
  - `test_schema_nuevo_tiene_tablas_fase5`: importa `schema.sql` en una BD vacía; las 26 tablas de `Installer::TABLES` existen.
  - `test_migracion_iguala_schema`: importa el schema de la Fase 4 (`git show 917ef12:database/schema.sql`), mete un usuario y un equipo y aplica la migración. Las columnas de `information_schema.COLUMNS` deben coincidir con el schema nuevo y el usuario y el equipo deben seguir ahí.
  - `test_installer_reconoce_reinstalacion`: `Installer::inspectDatabase()` devuelve `mode = 'reuse'` sobre el schema nuevo y `'partial'` sobre el de la Fase 4.
- [ ] **Step 2:** Run `php tests/run.php Schema` → FAIL (tablas inexistentes).
- [ ] **Step 3:** Escribir los cambios en `schema.sql` y en la migración (InnoDB, utf8mb4_unicode_ci, FKs `ON DELETE` coherentes: catálogos `RESTRICT`, referencias opcionales `SET NULL`, hijos de tarea/aviso `CASCADE`) y ampliar `Installer::TABLES`. La migración usa `ALTER TABLE … ADD COLUMN` sin `IF NOT EXISTS` (compatible con MySQL) y `LEEME.md` indica que se ejecuta **una sola vez**.
- [ ] **Step 4:** Run `php tests/run.php Schema` → PASS. Además `php /home/…/scratchpad/test_sync.php` sigue con `FALLOS: 0`.
- [ ] **Step 5: Commit** `feat(db): esquema y migración Fase 5 + arnés de pruebas`

### Task 2: Roles y alcance por instalación

**Files:**
- Create: `src/Core/Roles.php`, `src/Services/Alcance.php`, `tests/AlcanceTest.php`
- Modify: todos los `['agente_ti', 'admin_ti']` y `rol IN ('agente_ti', 'admin_ti')` de `src/` (15 archivos listados por `grep -rln agente_ti src`)

**Interfaces:**
- Produces: `Roles::TI = ['agente_ti', 'supervisor', 'admin_ti']`, `Roles::SUPERVISION = ['supervisor', 'admin_ti']`, `Roles::label(string $rol): string`.
- Produces `Alcance` (constructor `PDO`):
  - `instalacionesDe(int $usuarioId, ?string $fecha = null): list<int>` — admin = todas las activas; supervisor = `supervisor_instalaciones` vigentes ∪ sus `agente_instalaciones` vigentes; agente = `agente_instalaciones` vigentes (base y préstamo). Vigente: `(desde IS NULL OR desde <= hoy) AND (hasta IS NULL OR hasta >= hoy)`.
  - `instalacionesBase(int $agenteId): list<int>`
  - `supervisoresDe(int $instalacionId): list<array{id:int,nombre:string,email:?string}>`
  - `agentesDe(int $supervisorId): list<array{id:int,nombre:string,email:?string,prestado:bool}>`
  - `veTodo(array $user): bool` — true solo para `admin_ti`.
  - `puedeVerTicket(array $user, array $ticket): bool` — true si veTodo, o el ticket está asignado a él, o `ticket.instalacion_id` es NULL y el usuario es TI, o la instalación ∈ `instalacionesDe`.
  - `filtroTicketsSql(array $user, string $alias = 't'): array{0:string,1:list<mixed>}` — fragmento WHERE con placeholders, para la bandeja.

- [ ] **Step 1: Write the failing test** `tests/AlcanceTest.php`:
  - `test_prestamo_hasta_hoy_incluido_y_ayer_excluido` (Review Focus 1).
  - `test_supervisor_ve_sus_instalaciones_y_admin_todas`.
  - `test_ticket_sin_instalacion_visible_a_todo_ti_no_a_usuario`.
  - `test_ticket_asignado_visible_aunque_otra_instalacion`.
  - `test_filtro_sql_coincide_con_puedeVerTicket`: 6 tickets mixtos; mismo conjunto por ambos caminos.
- [ ] **Step 2:** Run `php tests/run.php Alcance` → FAIL.
- [ ] **Step 3:** Implementar `Roles` y `Alcance`; reemplazar las listas de roles por `Roles::TI` (y por `Roles::SUPERVISION` donde aplique); `Catalog::agentes()` incluye supervisores.
- [ ] **Step 4:** Run `php tests/run.php` → PASS; `grep -rn "'agente_ti', 'admin_ti'" src` → sin resultados.
- [ ] **Step 5: Commit** `feat: rol supervisor y servicio de alcance por instalación`

### Task 3: Catálogo físico, asignaciones y préstamos (pantallas)

**Files:**
- Create: `src/Services/UbicacionFisicaService.php`, `src/Services/AsignacionService.php`, `src/Controllers/InstalacionController.php` (admin), `src/Controllers/SupervisorController.php`, vistas en `src/Views/instalaciones/*` y `src/Views/supervisor/*`, `tests/UbicacionFisicaTest.php`
- Modify: `index.php` (rutas), `src/Views/layouts/main.php` (menú)

**Interfaces:**
- Produces `UbicacionFisicaService`:
  - `catalogo(?array $instalacionIds = null): array` — árbol JSON-serializable `[{id,nombre,ubicaciones:[{id,nombre,tipo,indicaciones,niveles:[{id,nombre}],alas:[string]}]}]`; si no hay niveles habilitados → `[{id:<Único>,nombre:'Único'}]`; si no hay alas → `['na']`.
  - `validar(array $in): array{instalacion_id:int,ubicacion_fisica_id:int,nivel_id:int,ala:string,referencia:?string}` — lanza `ValidationException`.
  - `etiqueta(int $ubicacionFisicaId, ?int $nivelId, ?string $ala): string` → p. ej. `"Zona Industrial Km 4.5 › Edificio Holkanaab › Nivel 1 › Ala Norte"`.
  - `instalacionDeInmuebleGeca(?string $inmueble): ?int`
  - `hayCatalogo(): bool`
  - Altas y ediciones: `guardarInstalacion`, `guardarUbicacion(int $instalacionId, array $in)` (nombre, tipo, indicaciones, niveles[], alas[]), `mapearInmueble(string $inmueble, ?int $instalacionId)`.
- Produces `AsignacionService`:
  - `asignarSupervisor(int $supervisorId, int $instalacionId, string $tipo, ?string $desde, ?string $hasta, int $por)`
  - `asignarAgente(...)`, `prestarAgente(int $agenteId, int $instalacionDestino, string $desde, string $hasta, int $por)`
  - `quitar(string $tabla, int $id, int $por)`
  - El supervisor solo opera sobre sus instalaciones y sus agentes; el préstamo exige `hasta`.
- Rutas:
  - Admin: `/ti/instalaciones` (CRUD de instalaciones, tipos y niveles, equivalencias GECA, supervisores).
  - Supervisor: `/supervisor` (panel), `/supervisor/ubicaciones`, `/supervisor/agentes` (asignar, prestar, quitar).

- [ ] **Step 1: Write the failing test** `tests/UbicacionFisicaTest.php`:
  - `test_ejemplos_del_usuario` crea los dos ejemplos del spec 7.1 y espera las etiquetas `"Zona Industrial Km 4.5 › Edificio Holkanaab › Nivel 1 › Ala Norte"` y `"Zona Industrial Laguna Azul › Area de Carga › Nivel Único › Ala N/A"`.
  - `test_validar_rechaza_nivel_no_habilitado`.
  - `test_validar_rechaza_ubicacion_de_otra_instalacion`.
  - `test_supervisor_no_puede_prestar_agente_ajeno`.
  - `test_prestamo_requiere_hasta`.
- [ ] **Step 2:** Run `php tests/run.php UbicacionFisica` → FAIL.
- [ ] **Step 3:** Implementar los servicios, los controladores y las vistas (formularios con CSRF, confirmaciones con `data-confirm`). En las pantallas de ubicación, los niveles y las alas son casillas sobre la plantilla.
- [ ] **Step 4:** Run `php tests/run.php` → PASS. HTTP: el admin crea una instalación y le asigna supervisor; el supervisor crea “Edificio Holkanaab” con nivel 1 y ala Norte; un agente recibe 403 en `/supervisor/agentes`.
- [ ] **Step 5: Commit** `feat: catálogo de ubicaciones físicas, asignaciones y préstamos`

### Task 4: Selector físico en tickets, regla D por instalación, bandeja y correo de ticket nuevo

**Files:**
- Create: `assets/js/ubicacion-picker.js`, `src/Views/partials/ubicacion_picker.php`, `tests/TicketFisicoTest.php`
- Modify:
  - `src/Services/TicketService.php` (`create`, `find`): agrega `ubicacion_fisica`, `indicaciones` e `instalacion_id`.
  - `src/Controllers/TicketController.php`, `src/Controllers/TiController.php` (bandeja con `Alcance::filtroTicketsSql`, vista “Sin instalación”, `show`, `comment`, `manage` y `take` con `puedeVerTicket`).
  - `src/Views/tickets/create.php`, `src/Views/tickets/show.php`.
  - `src/Services/Mail/Notifier.php`: `ticketCreado` va a los supervisores de la instalación, a `mail.mesa_institucional` y a `notify_new_tickets_to`. Ya **no** va a todos los agentes.
  - `src/Controllers/ReporteController.php`: el supervisor ve sus instalaciones, con un selector por instalación.
  - `config/config.example.php` y la generación en `Installer::buildConfig` (`mail.mesa_institucional`).

**Interfaces:**
- Consumes: `UbicacionFisicaService::{catalogo,validar,etiqueta,instalacionDeInmuebleGeca,hayCatalogo}`, `Alcance::*`.
- Produces: el picker recibe `$catalogo` y `$prefijo` (`reportada`|`habitual`) y envía `{prefijo}_ubicacion_fisica_id`, `{prefijo}_nivel_id`, `{prefijo}_ala` y `referencia`; los selects en cascada salen del JSON incrustado en `data-catalogo`.
- Reglas de `TicketService::create`:
  - La ubicación física es obligatoria si `hayCatalogo()`.
  - `tickets.instalacion_id` = instalación de la ubicación reportada.
  - Con equipo: siempre se actualizan `equipos.real_*`.
  - Discrepancia si `instalacionDeInmuebleGeca(inmueble GECA del equipo)` no es NULL y es distinta de la reportada; en ese caso se genera el ticket vinculado, como hoy.
  - El ticket vinculado hereda `instalacion_id`.

- [ ] **Step 1: Write the failing test** `tests/TicketFisicoTest.php`:
  - `test_discrepancia_por_instalacion`.
  - `test_misma_instalacion_otro_edificio_no_discrepa_pero_actualiza_real`.
  - `test_inmueble_sin_equivalencia_no_discrepa`.
  - `test_ubicacion_obligatoria_con_catalogo`.
  - `test_sin_catalogo_se_acepta_sin_ubicacion`.
  - `test_destinatarios_ticket_nuevo` (supervisor de la instalación + institucional, sin agentes): usar `Notifier` con un `Mailer` de prueba que registra los mensajes. Agregar `Notifier::setMailerForTests(?Mailer $m)`.
- [ ] **Step 2:** Run `php tests/run.php TicketFisico` → FAIL.
- [ ] **Step 3:** Implementar. El selector GECA se retira del formulario; en `show` se muestran la etiqueta física, las **Indicaciones del lugar** (alerta destacada para TI), la referencia y la ubicación GECA del equipo, solo como dato.
- [ ] **Step 4:** Run `php tests/run.php` → PASS. Por HTTP: un agente de la instalación A no abre (404) un ticket de la B; un ticket sin instalación aparece en “Sin instalación” para todo TI.
- [ ] **Step 5: Commit** `feat: ubicación física en tickets, discrepancia por instalación y alcance en bandeja`

### Task 5: Registro con correo de empresa verificado, Mi cuenta y verificación de cuentas existentes

**Files:**
- Create: `src/Services/VerificacionEmailService.php`, `src/Views/auth/verificacion_pendiente.php`, `tests/VerificacionTest.php`
- Modify:
  - `src/Controllers/RegistroController.php` y `src/Views/auth/registro_completar.php` (picker `habitual`, extensión y correo obligatorios).
  - `src/Controllers/CuentaController.php` y `src/Views/cuenta/index.php` (picker; cambiar de correo exige verificar de nuevo).
  - `src/Core/Auth.php` (`attempt` rechaza `registrado = 0`; agrega `Auth::requiereVerificacion(): bool`).
  - `src/Controllers/Controller.php` (`requireLogin` redirige a `/verificar-correo` si `email_verificado_en` es NULL).
  - `src/Controllers/UsuarioController.php` (admin: “Marcar correo como verificado”).
  - `index.php` (rutas `GET /verificar/{token}`, `GET|POST /verificar-correo` para reenviar).
  - `config/config.example.php` y `Installer::buildConfig` (`registro.dominios_permitidos`).

**Interfaces:**
- Produces `VerificacionEmailService`:
  - `dominioPermitido(string $email): bool` — compara el dominio exacto, sin distinguir mayúsculas.
  - `emitir(int $usuarioId, string $email): string` — token de 32 bytes en base64url; guarda `hash('sha256')`; expira en 24 h; invalida los tokens previos del usuario.
  - `verificar(string $token): ?int` — devuelve el `usuario_id`, marca `usado_en`, pone `email_verificado_en = NOW()`, `registrado = 1` y aplica `email` si cambió.
  - `puedeReenviar(int $usuarioId): bool` — máximo 5 por hora.
- Flujo de registro (spec 7.6 bis):
  1. Se guardan los datos con `registrado = 0` y se envía el enlace.
  2. Al verificar, se activa la cuenta y se inicia sesión.
  3. `Notifier::usuarioRegistrado(int $usuarioId)` avisa a los supervisores de la instalación habitual.

- [ ] **Step 1: Write the failing test** `tests/VerificacionTest.php`:
  - `test_dominios` (Review Focus 3, valores exactos).
  - `test_token_un_solo_uso`.
  - `test_token_vencido`.
  - `test_token_ajeno_no_activa_otra_cuenta` (Review Focus 4).
  - `test_verificar_activa_y_notifica_supervisor` (con `Notifier::setMailerForTests`).
  - `test_cuenta_existente_sin_verificar_queda_bloqueada` (vía `Auth::requiereVerificacion`).
- [ ] **Step 2:** Run `php tests/run.php Verificacion` → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Run `php tests/run.php` → PASS. HTTP de punta a punta con el sumidero SMTP: registro → correo con enlace → abrir el enlace → sesión iniciada → el supervisor recibe el aviso.
- [ ] **Step 5: Commit** `feat: registro con correo de empresa verificado y aviso al supervisor`

### Task 6: Contraseña de admin local — recordatorios, bloqueo total y cron

**Files:**
- Create: `src/Services/AdminLocalService.php`, `src/Services/Recordatorios.php`, `src/Controllers/AdminLocalController.php`, `src/Views/admin_local/registrar.php`, `bin/cron.php`, `bin/.htaccess`, `tests/AdminLocalTest.php`
- Modify:
  - `src/Controllers/Controller.php` (`requireLogin`: si `AdminLocalService::bloqueado($user)` → `redirect('/admin-local')`; quedan exentas `/admin-local`, `/logout` y `/verificar*`).
  - `src/Views/layouts/main.php` (aviso en pantalla a 10 días o menos).
  - `index.php`: rutas `GET|POST /admin-local`. Además, después del despacho, `Recordatorios::ejecutarSiPendiente()` (respaldo diario con `GET_LOCK` y `sistema_estado.ultima_ejecucion`).
  - `.htaccess`: agrega `bin` a las carpetas bloqueadas.

**Interfaces:**
- Produces `AdminLocalService`:
  - `vence(?string $cambiadoEn): ?string` (Y-m-d, +3 meses calendario).
  - `diasRestantes(?string $cambiadoEn, ?string $hoy = null): ?int`
  - `bloqueado(array $user): bool` — solo roles TI; `null` o ≤ 5 → true.
  - `semaforo(?int $dias): string` — `'verde'|'amarillo'|'rojo'`.
  - `registrar(int $usuarioId, string $fecha)` — no acepta fecha futura ni anterior a 3 meses.
- Produces `Recordatorios`:
  - `ejecutar(string $hoy): array{enviados:int}`
    - Admin local: correo al agente y a los supervisores de su instalación base (al admin TI si es supervisor). Se envía en los umbrales 10, 9…6 solo una vez por umbral clave `"admin_local:{vence}:{dias}"`, y a diario a 5 días o menos.
    - Tareas que vencen mañana.
  - `ejecutarSiPendiente(): void`
- `bin/cron.php`: aborta si `PHP_SAPI !== 'cli'`; carga la configuración e imprime un resumen. La línea para cPanel va documentada en el README: `/usr/local/bin/php /home/USUARIO/public_html/bin/cron.php`.

- [ ] **Step 1: Write the failing test** `tests/AdminLocalTest.php`:
  - `test_vencimiento_tres_meses_calendario` (2026-11-30 → 2027-02-28).
  - `test_umbrales_bloqueo` (null → true, 5 → true, 6 → false; Review Focus 2).
  - `test_usuario_normal_nunca_bloqueado`.
  - `test_semaforo` (11 verde, 10 amarillo, 6 amarillo, 5 rojo, -1 rojo).
  - `test_cron_no_duplica_mismo_dia`.
  - `test_cron_avisa_supervisor_base_no_al_de_prestamo`.
- [ ] **Step 2:** Run `php tests/run.php AdminLocal` → FAIL.
- [ ] **Step 3:** Implementar. La pantalla `/admin-local` explica por qué está bloqueado y pide la fecha de cambio, con la casilla “Confirmo que ya cambié mi contraseña de admin local”.
- [ ] **Step 4:** Run `php tests/run.php` → PASS. HTTP: un agente sin fecha es redirigido a `/admin-local` desde `/ti/tickets` y desde `/`; tras registrar la fecha entra normal. `php bin/cron.php` imprime el resumen; por HTTP, `/bin/cron.php` devuelve 403.
- [ ] **Step 5: Commit** `feat: control de contraseña de admin local con bloqueo total y cron`

### Task 7: Avisos en el inicio

**Files:**
- Create: `src/Services/AvisoService.php`, `src/Controllers/AvisoController.php`, `src/Views/avisos/index.php`, `src/Views/avisos/form.php`, `src/Views/partials/avisos.php`, `tests/AvisoTest.php`
- Modify:
  - `src/Views/dashboard/index.php` y `src/Views/auth/login.php` (el login solo muestra los avisos de alcance `todos`).
  - `src/Controllers/AuthController.php` y `src/Controllers/DashboardController.php` (pasan los avisos vigentes).
  - `index.php` (rutas `/supervisor/avisos` para CRUD con `Roles::SUPERVISION`).

**Interfaces:**
- Produces `AvisoService`:
  - `vigentesPara(?array $user, ?string $ahora = null): list<array>` — `null` = pantalla de login. Para un usuario: los de alcance `todos` más los de sus instalaciones (para TI, con `Alcance`; para un trabajador, su instalación habitual física o, si no tiene, la de su equipo principal: física real y luego la equivalencia GECA). Orden: urgente, importante, informativo; luego `inicia_en DESC`.
  - `guardar(?int $id, array $in, array $autor): int` — el supervisor solo dirige avisos a sus instalaciones; `termina_en` debe ser posterior a `inicia_en`.

- [ ] **Step 1: Write the failing test** `tests/AvisoTest.php`:
  - `test_fuera_de_vigencia_no_se_muestra`.
  - `test_alcance_instalacion_por_ubicacion_habitual`.
  - `test_trabajador_sin_instalacion_solo_ve_todos`.
  - `test_login_solo_todos`.
  - `test_supervisor_no_publica_en_instalacion_ajena`.
- [ ] **Step 2:** Run `php tests/run.php Aviso` → FAIL.
- [ ] **Step 3:** Implementar. Tarjetas por importancia, siempre con ícono y texto (no solo color).
- [ ] **Step 4:** Run `php tests/run.php` → PASS.
- [ ] **Step 5: Commit** `feat: avisos con vigencia y alcance por instalación`

### Task 8: Tareas con folio, entregas por agente y evidencias

**Files:**
- Create: `src/Services/TareaService.php`, `src/Services/ArchivoService.php`, `src/Controllers/TareaController.php`, vistas `src/Views/tareas/{index,form,show}.php`, `storage/tareas/.gitignore`, `tests/TareaTest.php`
- Modify: `index.php` (rutas), `src/Services/Mail/Notifier.php` (`tareaAsignada`, `tareaEntregada`, `tareaRevisada`, `tareaPorVencer`), `src/Services/Recordatorios.php` (tareas que vencen mañana), `src/Views/layouts/main.php` (menú “Tareas”).

**Interfaces:**
- Produces `ArchivoService`:
  - `guardar(array $upload, string $subcarpeta): array{ruta:string,nombre_original:string,mime:string,tamano:int}`
    - Valida que la extensión esté en la lista permitida y que el MIME (`finfo`) corresponda.
    - Rechaza archivos de más de 10 MB y cualquier contenido que empiece con `<?php`.
    - Guarda con nombre `bin2hex(random_bytes(16)).ext`.
  - `enviar(array $archivo): never` — `Content-Disposition: attachment` con `nombre_original` saneado y `X-Content-Type-Options: nosniff`.
- Produces `TareaService`:
  - `crear(array $supervisor, array $in, array $archivos): int` — `agentes[]` deben estar en `Alcance::agentesDe`; folio `TA-AAAA-NNNNNN`.
  - `cambiarEstado(int $asignacionId, array $user, string $accion, ?string $comentario)` — acciones: `iniciar` (agente), `entregar` (agente; requiere al menos 1 evidencia si la tarea pide evidencias), `aceptar` y `devolver` (supervisor de la tarea).
  - `avance(int $tareaId): array{total:int,entregadas:int,aceptadas:int}`
  - `puedeDescargar(array $user, array $archivo): bool`
- Rutas: `GET /tareas` (agente: mis tareas; supervisor: las que asigné), `GET|POST /tareas/nueva`, `GET /tareas/{id}`, `POST /tareas/asignacion/{id}/iniciar`, `…/entregar` (multipart con evidencias), `…/aceptar`, `…/devolver`, `GET /tareas/archivo/{id}` (el router solo admite un `{id}`).
- Bloqueo: en `cambiarEstado`, los roles TI con `AdminLocalService::bloqueado` no pueden operar (la redirección global de Task 6 ya lo cubre; prueba de respaldo).

- [ ] **Step 1: Write the failing test** `tests/TareaTest.php`:
  - `test_folio_formato`.
  - `test_cada_agente_entrega_lo_suyo` (avance 1 de 2).
  - `test_devolver_regresa_a_en_proceso_con_comentario`.
  - `test_entregar_sin_evidencia_requerida_falla`.
  - `test_agente_ajeno_no_descarga_evidencia` (Review Focus 5).
  - `test_rechaza_php_y_doble_extension` (Review Focus 5).
  - `test_supervisor_no_asigna_agente_ajeno`.
- [ ] **Step 2:** Run `php tests/run.php Tarea` → FAIL.
- [ ] **Step 3:** Implementar (subida múltiple, lista de evidencias requeridas como casillas, estado “vencida” calculado si `entrega_en` < ahora y no aceptada).
- [ ] **Step 4:** Run `php tests/run.php` → PASS. HTTP: subir una evidencia y descargarla siendo su dueño (200) y siendo otro agente (404); `/storage/tareas/...` directo → 403.
- [ ] **Step 5: Commit** `feat: tareas del supervisor con entregas y evidencias por agente`

### Task 9: Encabezado del usuario, tablero del trabajador, panel del supervisor y tema visual

**Files:**
- Create: `assets/css/theme.css`
- Modify: `src/Views/layouts/{main,guest,installer}.php` (Google Fonts Roboto 400/500/700 y Roboto Condensed 500/700; `theme.css`), `src/Views/dashboard/index.php`, `src/Controllers/DashboardController.php`, `src/Views/supervisor/panel.php` (semáforo de agentes, tareas pendientes de revisar, tickets de sus instalaciones), `src/Core/Auth.php` (`user()` agrega la etiqueta de la ubicación habitual y `telefono_ext`)

**Interfaces:**
- Consumes: `UbicacionFisicaService::etiqueta`, `AdminLocalService::{diasRestantes,semaforo}`, `Alcance::agentesDe`, `TareaService::avance`.
- Encabezado (spec 7.6 ter): bajo el nombre, “📍 {instalación} › {ubicación}[ › Nivel][ › Ala] · Ext. {telefono_ext}”, con enlace a `/cuenta`; si no tiene ubicación: “Captura tu ubicación”.
- Tablero del trabajador: abiertos, en espera de su respuesta, resueltos en 30 días y los 5 más recientes.
- Tema: variables `--bs-primary` (azul petróleo `#0b5d73`), acento verde azulado `#14a39a`, fondo `#f4f7f9`, radios de 0.75rem y sombras suaves. Roboto Condensed en `h1–h6`, `.navbar`, `th`, `.badge`, `.btn`. Validar el contraste AA del texto sobre el primario.

- [ ] **Step 1:** Revisión visual con Playwright (escritorio 1280 px y móvil 390 px) de inicio del trabajador, inicio del supervisor, bandeja, nuevo ticket y login. Checklist: fuentes cargadas (`document.fonts.check('16px Roboto')`), sin desbordes horizontales y encabezado legible en móvil.
- [ ] **Step 2:** Implementar.
- [ ] **Step 3:** Repetir la revisión visual; `php tests/run.php` → PASS.
- [ ] **Step 4: Commit** `feat: encabezado con ubicación, tableros y tema Roboto`

### Task 10: Documentación, regresión completa y paquete de entrega

**Files:**
- Modify: `CLAUDE.md` (Fase 5 hecha y reglas nuevas), `README.md` (migración en phpMyAdmin, Tarea Cron, configuración de correo, nuevos roles)
- Create (fuera del repo, en el scratchpad): `ticketsti-fase5-cambios.zip`, con solo los archivos agregados o modificados desde `917ef12`, sin `tests/` ni `docs/`, más `database/migraciones/2026-11-23_fase5.sql` y `LEEME-ACTUALIZACION.txt` (orden: respaldo de la BD → migración → subir archivos → agregar las claves nuevas a `config/config.php` → crear la Tarea Cron).

- [ ] **Step 1:** Run `php tests/run.php` → todas PASS; `test_sync.php` → `FALLOS: 0`.
- [ ] **Step 2:** Instalación limpia con el asistente sobre una BD vacía. Actualización: BD con datos de la Fase 4, migración y archivos nuevos; todas las rutas responden 200/303 sin errores en `storage/logs`.
- [ ] **Step 3:** Generar el zip de cambios: `git diff --name-only --diff-filter=AMR 917ef12 HEAD -- . ':!tests' ':!docs'` → `zip`.
- [ ] **Step 4: Commit y push** `docs: Fase 5` → `git push origin claude/geca-cpanel-architecture-jjj6ax`
