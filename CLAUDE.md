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
* **Tareas programadas:** `bin/cron.php` (Tarea Cron de cPanel **cada hora**: recordatorios diarios sin duplicar + escalamiento SLA); respaldo desde las peticiones web (`Recordatorios::ejecutarSiPendiente` una vez al día, `SlaService::escalarSiPendiente` cada 15 min).
* **Gráficas:** `Core/Grafica` genera SVG/HTML en el servidor (sin librerías). Paleta validada con la guía de visualización: serie 1 `#257de0`, serie 2 `#f47230`; estados `#0aac23`/`#fbb315`/`#dc3c3d` siempre con ícono y texto; cada gráfica trae "Ver como tabla".
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
* Acceso por rol con `$this->requireRole([...])`; roles en `Core/Roles`: `usuario`, `agente_ti`, `supervisor`, `coordinador`, `admin_ti` (`Roles::TI`, `Roles::SUPERVISION`, `Roles::COORDINACION`). En SQL usa `Roles::sqlIn(Roles::TI)`, nunca listas de roles escritas a mano.
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
  * Las reglas C y D viven en `Services/TicketService`. Folio `TK-AAAA-NNNNNN` (aleatorio desde la Fase 6).
  * El ticket `actualizacion_ubicacion` se genera una sola vez por equipo mientras siga abierto; los siguientes reportes se agregan como nota interna.
  * Enrutamiento automático por tipo: incidencia y solicitud → Soporte Técnico en Sitio; garantía → Garantías y Proveedores; actualización de ubicación → Control de Resguardos GECA.
  * Los cambios hechos por TI quedan en la bitácora del ticket y el solicitante los ve. Las notas internas solo las ve TI.
  * Política de contraseñas: `Core/PasswordPolicy` (10 caracteres para trabajadores, 12 para el admin del instalador).
* **Fase 5 (hecha):** supervisores por instalación (titular/suplencia con fechas), agentes asignados y préstamos temporales; ubicaciones físicas Instalación → Ubicación → Nivel → Ala con indicaciones del lugar y referencia; regla D por instalación (equivalencias inmueble GECA → instalación); registro con correo de empresa verificado (enlace 24 h) y aviso al supervisor; contraseña de admin local (avisos desde 10 días, bloqueo total a ≤ 5); avisos con vigencia; tareas con folio `TA-AAAA-NNNNNN`, entregas y evidencias por agente; encabezado con ubicación y extensión; tema Roboto. Diseño en `docs/superpowers/specs/2026-11-23-supervisores-ubicaciones-tareas-design.md`; migración en `database/migraciones/2026-11-23_fase5.sql`.
* **Fase 6 (hecha):**
  * GECA por componente (reporte "Equipos entregados con estatus"); noticias con imagen en la pantalla de entrada (2/3 carrusel 16:9, 1/3 acceso).
  * Folios aleatorios `TK/TA-AAAA-NNNNNN` (6 dígitos, `Core/Folio`, se re-sortea si se repite; los antiguos no cambian).
  * Cuentas que crea el Admin TI (`CuentaService`, *Usuarios › Crear cuenta*): `tipo_cuenta` `administracion` (p. ej. `ADMIN-TI`, sin contraseña de admin local) y `prueba` (fuera de reportes y tablero). Nacen activas y verificadas; cualquier dominio de correo.
  * Ficha del trabajador `/ti/usuarios/{id}` (todo TI; acciones de cuenta solo Admin TI): datos GECA aunque no haya activado, dónde está, equipos con instalación, equipos que tuvo antes y tickets en alcance.
  * Inventario por instalación (`InventarioService`): instalación del equipo = la de su ubicación física real; si no tiene, la equivalencia del inmueble GECA. Filtro "Mis instalaciones" por omisión; tarjetas de equipos y técnicos en el panel. El supervisor relaciona inmuebles GECA libres con sus instalaciones.
  * Rol **Coordinador** por zonas (`zonas`, `instalaciones.zona_id`, `coordinador_zonas` titular/suplencia): ve y supervisa las instalaciones de sus zonas, asigna supervisores en ellas, recibe los avisos de admin local de esos supervisores y los tickets/registros de instalaciones sin supervisor. Lo asigna el Admin TI en *Gestión › Zonas*.
  * Migraciones `2026-11-23_geca_componentes.sql`, `2026-11-24_avisos_imagen.sql`, `2026-11-24_coordinadores_cuentas.sql`.
* **Fase 7 (hecha):**
  * Mensajes (`partials/flash`): fondo blanco, borde pastel por tipo, ícono, animación; éxito se cierra a los 6 s. Avisos con acuse de lectura (`aviso_lecturas`, `avisos.requiere_confirmacion`): laten hasta "Enterado"; campana con pendientes; reporte "Leído por X de N" (`/supervisor/avisos/{id}/lectura`).
  * Tiempos de atención (SLA) configurables por prioridad (`sla_prioridades`, *Gestión › Tiempos de atención*, `SlaService`); `tickets.atendido_en` = primera acción de TI; semáforo en bandeja y ticket; vista "Fuera de tiempo"; escalamiento una vez por ticket (respuesta → supervisores; resolución → coordinadores; si no hay, Admin TI).
  * Adjuntos en tickets y comentarios (`ticket_archivos`, `storage/tickets`, máx. 5 × 10 MB); los de notas internas solo TI; descarga `/tickets/archivo/{id}`.
  * "Mi día" del agente (`TableroService::rutaAgente`, `misAsignados`): asignados por urgencia, ruta instalación › edificio › nivel con indicaciones; bandeja filtra por `ubic`/`nivel`.
  * Tableros de supervisor y coordinador (`TableroService::panel`): indicadores, tendencia 30 días, semáforo SLA, por tipo, por instalación, por edificio, equipos por tipo y tabla por zona › instalación.
  * Migración `2026-11-27_sla_avisos_adjuntos.sql`.
* **Fase 8 (hecha):**
  * BitLocker por equipo (`BitLockerService`, `/equipos/{id}/bitlocker`): identificador obligatorio (8 caracteres o GUID) y llave de 48 dígitos opcional, **cifrada** con `Core/Cifrado` (libsodium; clave en `security.clave_cifrado` o `storage/keys/cifrado.key`, que no debe perderse). Capturan y ven el resguardante y el TI de la instalación del equipo (sin instalación: cualquier TI; admin: todo). Ver la llave pide la contraseña y un motivo, y queda en `bitlocker_consultas`. **Nunca** viaja en correos, exportaciones ni reportes.
  * Celular opcional (`usuarios.celular`) en registro y Mi cuenta, con aviso de doble autenticación (`VerificacionEmailService::celular`).
  * Correo de ticket nuevo/asignado para TI y Remedy con ficha completa (`Notifier::fichaCompleta`): solicitante, extensión, celular, jefe, ubicación física con indicaciones, GECA, equipo, garantía, adjuntos y límites SLA. La confirmación al trabajador sigue breve.
  * Asignación inteligente (`AsignadorService`): candidatos vigentes y disponibles de la instalación, sin bloqueo de admin local y con la especialidad (`agente_departamentos`; sin filas = todas); puntos = carga abierta ponderada (crítica 4, alta 3, media 2, baja 1) + 3 si ocupado − 1 si ya atiende ese edificio; empate por turnos. `instalaciones.modo_asignacion`: `manual`, `sugerida` (por omisión, `tickets.sugerido_agente_id` y botón "Asignar") o `automatica` (asigna, bitácora y correo). Disponibilidad (`agente_estado`: disponible/ocupado/fuera/vacaciones con fecha "hasta") desde el encabezado del agente o *Supervisión › Agentes*.
  * Migración `2026-11-28_bitlocker_asignacion.sql`.
* **Fase 9 (hecha):**
  * Módulos con control del Admin TI (`ModuloService`, tabla `modulos`, *Gestión › Módulos*): `garantias`, `bitlocker`, `inventario`, `reportes`, `tareas`, `avisos` (publicar) y `plantillas`; encendido/apagado y perfiles (solo los de `ModuloService::POSIBLES`; `admin_ti` siempre). En controladores `$this->requireModulo('clave', [roles])`; en vistas `modulo('clave')`. Sin la tabla (migración pendiente) aplica `POR_OMISION`.
  * Correo (`Mail/CorreoAjustes`, tabla `ajustes`, *Gestión › Correo y Remedy*): el Admin TI captura el correo de Remedy y las copias fijas (respaldo: `mail.mesa_institucional`/`notify_new_tickets_to` del config); supervisores y coordinadores encienden o apagan Remedy por instalación (`instalaciones.remedy_activo`, *Supervisión › Agentes*).
  * Plantillas de correo (`PlantillaService`, `plantillas_correo`, `correos_enviados`, *Gestión › Plantillas de correo*): las gestionan supervisión y admin; todo TI las envía desde el ticket (al solicitante; queda "[Correo enviado]" en el seguimiento) o desde la ficha del trabajador. Variables `{nombre} {ficha} {folio} {titulo} {instalacion} {agente} {fecha}`.
  * Garantías: el trabajador ve su historial de solo lectura (`/garantias`, `GarantiaService::deUsuario`); agentes y supervisores registran y modifican (`garantias.capturado_por`, `actualizado_por`).
  * Migración `2026-11-29_modulos_correo.sql`.
* **Fase 10 (hecha):**
  * Delegación (`DelegacionService`, `delegaciones`): el jefe autoriza en *Mi cuenta* a una o varias personas (ficha, fecha "hasta" opcional, `ver_todos`); si el jefe no ha activado su cuenta, lo registra supervisión de TI desde su ficha (Admin TI siempre). La persona autorizada elige "¿Para quién es?" en *Nuevo ticket* (`?para=ID`): equipos y ubicación del jefe; el ticket queda a nombre del jefe con `tickets.creado_por_id`. Ve y comenta esos tickets (`DelegacionService::puedeVerTicket` en `TicketController::acceso`, `filtroSql` en *Mis tickets*); avisos al jefe con copia a ella; TI ve "Levantado por" con extensión y celular. No da acceso a BitLocker.
  * BitLocker en el menú (`/bitlocker`): mis equipos con sus llaves y, para TI, buscador por identificador (basta el inicio que dicta el usuario), serie o ficha dentro del alcance (`BitLockerService::buscar`, `misEquipos`).
  * Migración `2026-11-30_delegaciones.sql`.
* **Fase 11 (hecha):**
  * Formatos con firma (`FormatoService`, módulo `formatos`): `formato_plantillas` por año y versión (logos izq/der/pie y textos en JSON; texto oficial en `FormatoService::DEFAULT`; si no hay versión del año se usa la más reciente), `formatos` (folio `FF-AAAA-NNNNNN`, `codigo` de verificación, motivos, método, estado) y `formato_firmas`. Firmas en orden usuario → responsable (jefe inmediato por `jefe_ficha`, otro jefe por ficha, o el propio usuario con motivo) → TI con método; en su sesión, presencial en el dispositivo del técnico o en papel (escaneo en `storage/formatos/escaneos`). Cada firma pide la contraseña de quien está en sesión y queda en la bitácora del ticket. Al completarse se congelan los datos (`formatos.datos`), salvo el folio Remedy, que se lee del ticket.
  * Ticket tipo `formateo` (exige equipo y genera el formato); TI lo genera desde cualquier ticket con equipo. El ticket no se cierra con un formato pendiente ni sin `tickets.folio_remedy` cuando la instalación envía a Remedy (`CorreoAjustes::exigeFolioRemedy`). `equipos.inventario` (TI) e `instalaciones.ciudad` (lugar y fecha).
  * Hoja carta (`partials/formato_hoja`, `assets/css/formato.css`, `/formatos/{id}/imprimir`), firma dibujada (`assets/js/firma.js`), verificación (`/formatos/verificar`), *Gestión › Formatos* (`FormatoAdminController`): logos y textos por año con vista previa; editar una versión con documentos crea otra.
  * Texto del formato vigente (Claudio, 2026): etiquetas editables (`FormatoService::ETIQUETAS`; las versiones sin `etiquetas` se ven con `ETIQUETAS_ANTERIORES`), `leyenda_firma` vacía (sin "Nombre, ficha y firma"), `formato_lugar` `{lugar}, a {fecha}`, nota *** completa. Una versión sin `etiquetas` y sin documentos se actualiza sola al texto vigente conservando logos.
  * Migración `2026-12-01_formatos.sql`.
* **Fase 12 (hecha):**
  * Supervisión levanta tickets a nombre de trabajadores de sus instalaciones (por ficha en *Nuevo ticket*; `Alcance::puedeLevantarPara`: ubicación habitual o algún equipo en la instalación). Queda `tickets.creado_por_id`. La delegación del jefe sigue igual.
  * Borrar instalaciones (Admin TI) y ubicaciones (supervisor) aunque estén en uso (`UbicacionFisicaService::eliminarInstalacion`/`eliminarUbicacion`, `uso`): tickets, equipos y usuarios quedan sin instalación/ubicación; supervisores, agentes, equivalencias y avisos de la instalación se borran.
  * Cobertura por ubicación (`CoberturaService`, `agente_ubicaciones`; sin filas = todas) y pausas del supervisor por ubicación o instalación (`agente_pausas`, motivo y "hasta"), en *Supervisión › Agentes*; el asignador las respeta. El agente ya no cambia su disponibilidad: solo supervisión.
  * ModSecurity del hosting: la firma viaja como archivo PNG y las equivalencias GECA por id de `ubicaciones` (solo filas cambiadas, `data-solo-cambios`), nunca con el texto del inmueble. Un `REDIRECT_STATUS` 403/413 de Apache se muestra con su causa.
  * Migración `2026-12-02_cobertura_agentes.sql`.
* **Fase 13 — Entrega A (hecha):**
  * Botones de selección (`btn-check`): sin marcar blanco con borde gris; marcado sólido con palomita (`theme.css`).
  * Categorías de la acción (`categorias`, `CategoriaService`, *Gestión › Categorías*): nombre + descripción de lo que abarca; obligatoria al crear (si la tabla existe), TI la corrige en el ticket (bitácora), "Por categoría" en reportes y CSV.
  * Tipos de ticket solo de TI en su ticket propio (`Catalog::TIPOS_TI`: tarea, actualizaciones, levantamiento, instrucción, formateos, bajas, altas) con varios equipos (`ticket_equipos`; búsqueda `/ti/equipos/buscar` y series pegadas, `TicketService::resolverEquipos`); quedan asignados a quien los levanta; ubicación opcional. Levantamiento, bajas y altas → Control de Resguardos GECA.
  * Migración `2026-12-03_categorias_tipos_ti.sql`.
* **Fase 13 — Entrega B (hecha):**
  * Tareas como hoja de reportes (`tarea_avances`, `TareaService::reportar`): el agente escribe reportes con cantidad atendida y evidencias (`tarea_archivos.avance_id`); al primero pasa a en proceso.
  * Meta opcional por agente (`tareas.meta_unidad` equipos/usuarios/otro + `meta_descripcion`, `meta_cantidad`); % = suma/meta (tope 100), `Core/Grafica::anillo`, barras en la lista, el panel del supervisor y la tarea. Al 100 % se avisa al supervisor una vez (`tarea_asignaciones.completa_avisada_en`, `Notifier::tareaCompleta`) y se marca "Lista para cierre".
  * "Cierre" = entregar; antes del 100 % exige motivo. El supervisor acepta o devuelve como antes.
  * Un ticket de TI puede "Sumar a mi tarea" (`tarea_asignacion_id`): agrega un reporte con sus equipos (o usuarios distintos) y liga el ticket.
  * Migración `2026-12-04_tareas_reportes.sql`.
* **Fase 13 — Entrega C (hecha):**
  * Módulo `resguardo` (`ResguardoService`, `ResguardoController`, *Gestión › Resguardo de equipos / Equipos disponibles*). Recepción `RE-AAAA-NNNNNN` (`recepciones`): por ficha o serie se listan sus equipos del GECA; se marca qué deja (monitores casados + `ResguardoService::ACCESORIOS`), condición y observaciones; motivo, instalación donde se guarda y acta del GECA escaneada opcional (`storage/resguardo`). Las actas de baja y responsivas las genera el GECA, no la app.
  * Por equipo (`equipos_resguardo`): en_resguardo → disponible → asignado (por ficha, "pendiente en GECA" hasta que la carga lo trae a su nombre: `confirmarConGeca`, se corre al listar) o dictamen / baja_definitiva. Alcance: instalaciones del usuario o sin instalación; admin todo.
  * Migración `2026-12-05_resguardo.sql`.
* **Equipos según el GECA (hecho):** en *Inicio* de supervisión (supervisor, coordinador, admin), por instalación supervisada: total, por inmueble, por referencia de ubicación (`equipos.ubicacion_referencia` normalizada, `InventarioService::referencia`) y por tipo, más tabla inmueble › referencia › tipo y CSV (`/supervisor/geca-ubicaciones?inst=`). Solo GECA: equipos vigentes cuyo inmueble está relacionado con la instalación (`InventarioService::gecaPorUbicacion`); monitores casados cuentan como MONITOR. Sin migración.
* **Diagnóstico y robustez:** *Gestión › Diagnóstico* (`DiagnosticoService`): migraciones pendientes (lee cada `.sql` y revisa tablas/columnas), extensiones, carpetas de `storage` con escritura, configuración y últimas líneas de `storage/logs/php-error.log`. Una tabla/columna faltante (SQLSTATE 42S02/42S22) muestra "falta importar una migración". BitLocker crea el cifrado solo al guardar o ver una llave (`BitLockerService::cifrado()`); sin sodium o sin permiso en `storage/keys` explica en pantalla en vez de error 500.
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
  3. Antes de tocar la base (pasada 1) se decide el **casado de monitores**: cada MONITOR va como monitor 1/2 de la computadora (CPU, PC, LAPTOP, CHROMEBOOK, MACBOOK) de su mismo `folio`; si su folio no trae computadora, de la **única** computadora libre (folio sin monitores, no en baja) de la misma ficha, con máximo 2. En cualquier otro caso queda como equipo propio. Nunca al azar.
  4. Por cada fila (una por componente; pasada 2):
     * Se inserta o recupera el ID en `ubicaciones` (`eps`, `subdireccion`, `gerencia`, `zona`, `sector`, `inmueble`; la colación ignora acentos).
     * Si el resguardo está en **BAJA**, el equipo se guarda **sin usuario** y la fila no crea ni actualiza usuarios.
     * Si la `ficha` no existe en `usuarios`, se inserta con `registrado = 0`. Si ya existe, se actualizan `nombre`, `jefe_ficha` y `jefe_nombre` sin tocar su contraseña, correo ni rol.
     * Los monitores casados no se guardan como equipo (sus datos van en `serie_monitorN` de la computadora). Replicadores, proyectores y monitores sin casar son equipos propios (`tipo_componente`).
     * Si la `serie` no existe en `equipos`, se inserta con `vigente_en_geca = 1` y `ubicacion_real_id = ubicacion_geca_id`. Si ya existe, se actualizan los campos del GECA (incluido el resguardo anterior `pr_*`), `usuario_id`, `ubicacion_geca_id` y `vigente_en_geca = 1`, **preservando intactos** `ubicacion_real_id`, `real_*`, `es_principal`, `garantias` y `tickets`.

### B. Registro y Autenticación de Usuarios
* **Auto-registro por Ficha:** El trabajador ingresa su número de `ficha`. El sistema busca en `usuarios` (`registrado = 0`).
* Si existe, muestra su nombre y jefe para confirmación; el usuario captura **obligatoriamente** su correo **de la empresa** (dominios en `registro.dominios_permitidos`), `telefono_ext`, contraseña y su **ubicación física actual** (`habitual_*`).
* La cuenta queda en `registrado = 0` hasta abrir el enlace enviado a su correo (24 h, un solo uso, se guarda solo el hash). Al verificar: `registrado = 1`, `email_verificado_en` y aviso a los supervisores de su instalación. Las cuentas sin `email_verificado_en` deben verificar al entrar; el admin puede marcarlo a mano.
* **Roles:** `usuario` (solicitante), `agente_ti` (técnico que atiende y sube GECA), `supervisor` (agente con funciones de supervisión de sus instalaciones), `coordinador` (encargado de una o varias zonas), `admin_ti` (gestión total).
* **Tipo de cuenta:** `geca` (llega con la carga y la activa el trabajador), `administracion` y `prueba` (las crea el Admin TI).
* **Contraseña de admin local (TI):** vence 3 meses calendario después de `admin_local_cambiado_en`; correo y aviso diario desde 10 días antes (agente → supervisores de su instalación base; supervisor → coordinadores de su zona o, si no hay, admin TI; coordinador/admin → admin TI); **bloqueo total** a 5 días o menos, vencida o sin fecha, hasta registrar el cambio en `/admin-local`. No aplica a cuentas `administracion`.

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
* Ticket nuevo → correo a los supervisores vigentes de su instalación (si no hay: coordinadores de su zona; si tampoco: admin TI) + `mail.mesa_institucional` (Remedy) + `notify_new_tickets_to`.
* Coordinadores: `coordinador_zonas` con la misma regla de vigencia; el coordinador cuenta como supervisor de todas las instalaciones de sus zonas (`Alcance::instalacionesSupervisadas`).
* **Avisos** (`avisos`): supervisores, coordinadores y admin; vigencia inicio–fin; alcance "todos" (también en el carrusel de la entrada) o instalaciones propias; con confirmación de lectura opcional (por omisión en urgente e importante).
* **Tareas** (`tareas`, `tarea_asignaciones`, `tarea_archivos`): una tarea, una entrega por agente con sus evidencias; estados asignada → en_proceso → entregada → aceptada | devuelta.

### E. Gestión de Garantías
* Módulo vinculado a `equipos` que permite registrar pólizas/contratos indicando si cubren el `equipo`, `monitor_1` o `monitor_2`, con fechas de vigencia, proveedor y estado (`vigente`, `en_reclamacion`, `vencida`).

### F. Formato del archivo GECA (reporte "Equipos entregados con estatus", 40 columnas, una fila por componente)

`folio, fecha, numero_orden, dia, mes, año, estado_resguardo, estado_inventario, numero_contrato, nombre, ficha, eps, subdireccion, gerencia, jefe_ficha, jefe_nombre, perfil, tipo_componente, serie, marca, modelo, lote, zona, sector, inmueble, ubicacion_referencia, entidad_federativa, municipio, usuario_fecha_aceptacion, ti_fecha_validacion, tipo_asignacion, motivo_fuera_consola, fecha_baja, pr_folio, pr_fecha, pr_estado_resguardo, pr_nombre, pr_ficha, pr_eps, pr_zona`

* Obligatorias: `GecaSyncService::REQUIRED` (22); las demás se guardan si vienen; `dia`, `mes`, `año` se ignoran. Diseño en `docs/superpowers/specs/2026-11-23-geca-por-componente-design.md`.
* Archivo real (18/11/2026): 124 317 filas / 21 MB → ~40 s y ~145 MB de memoria (límite del hosting: 256 MB, 300 s).

* Los encabezados se comparan sin acentos, mayúsculas, espacios ni signos (`"Número de Serie"` = `serie`, `"Jefe"` = `jefe_nombre`). Los alias aceptados viven en `GecaSyncService::ALIASES`.
* Fechas aceptadas: serial de Excel, `dd/mm/aaaa [hh:mm[:ss]] [a. m./p. m.]`, `aaaa-mm-dd [hh:mm[:ss]]`.
* Toda la carga corre en **una transacción** con `GET_LOCK` para impedir cargas simultáneas; ante cualquier error se revierte completa.
* Las ubicaciones guardan `''` (no `NULL`) en `zona`/`sector` vacíos para que el índice único `uk_ubicacion_completa` no admita duplicados.

---

## 4. Esquema de base de datos

La fuente de verdad es **`database/schema.sql`** (50 tablas; lista completa en `Installer::TABLES`) con semillas de `estados`, `departamentos`, `niveles`, `ubicacion_tipos`, `sla_prioridades`, `modulos` y `plantillas_correo`. Cualquier cambio de esquema se hace ahí **y** como script incremental en `database/migraciones/` importable desde phpMyAdmin (ver `database/migraciones/LEEME.md`); `tests/SchemaTest.php` verifica que ambos coincidan.
