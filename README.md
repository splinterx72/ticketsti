# ticketsti — Mesa de Ayuda TI y Control de Resguardos (GECA)

Sistema web ligero de tickets de soporte técnico integrado con el inventario oficial de resguardos **GECA**, diseñado para correr en **hosting compartido cPanel (NEUBOX)**: PHP 8.3+, MySQL/MariaDB y Apache, sin Docker, sin SSH y sin Composer.

> Reglas de negocio, convenciones y estado por fases: ver [`CLAUDE.md`](CLAUDE.md).
> Esquema de base de datos: [`database/schema.sql`](database/schema.sql).

## Funcionalidad actual

* Front Controller con URLs amigables (`.htaccess` + `index.php`), funciona en la raíz o en una subcarpeta.
* Carpetas internas (`src/`, `config/`, `database/`, `storage/`) y archivos sensibles (`.env`, `.sql`, `.md`, `.log`…) bloqueados por web.
* Inicio de sesión por ficha o correo, sesiones aisladas en `storage/sessions`, CSRF en todos los formularios y bloqueo temporal tras intentos fallidos.
* **Asistente de instalación web** (`/instalacion`) para hosting compartido: requisitos, clave privada, prueba de BD, creación de tablas, admin y `config.php`.
* **Auto-registro por ficha** (`/registro`): el trabajador confirma su nombre y jefe del GECA y crea su cuenta.
* **Portal del trabajador**: *Mis tickets*, *Nuevo ticket* (equipo principal preseleccionado, monitores casados, hostname `PM…`, componente afectado y ubicación física) y seguimiento con comentarios.
* **Detección de discrepancias de ubicación**: si el equipo está en otra ubicación que la del GECA, se actualiza su ubicación real y se abre un ticket para Control de Resguardos.
* **Bandeja de TI** (`/ti/tickets`): filtros, búsqueda, "Tomar", cambio de estado, prioridad, departamento y agente, y notas internas.
* **Garantías** (`/ti/garantias`): pólizas por equipo, monitor 1 o monitor 2, con alertas de "por vencer" y vencimiento automático; el ticket muestra si la falla está cubierta.
* **Inventario** (`/ti/equipos`): búsqueda por serie, monitor, ficha o inmueble, y ficha completa del equipo con su historial.
* **Avisos por correo**: ticket nuevo, respuestas, cambios de estado y asignaciones (función `mail()` del hosting o SMTP de cPanel).
* **Reportes** (`/ti/reportes`): indicadores por periodo, tablero de agentes, equipos con fallas recurrentes y exportación a CSV/Excel.
* **Usuarios** (`/ti/usuarios`): asignar roles de TI, desactivar cuentas y generar contraseñas temporales; **Mi cuenta** para que cada quien actualice sus datos y contraseña.
* **Supervisores por instalación**: cada supervisor responde por una o varias instalaciones (con suplencias), asigna agentes y los presta temporalmente; los tickets y tareas se ven solo donde cada quien está cargado.
* **Ubicaciones físicas**: Instalación → Ubicación (edificio, nave, taller, área) → Nivel → Ala, con indicaciones del lugar para los agentes; se muestran junto a la del GECA.
* **Registro con correo de la empresa verificado** y aviso al supervisor de la instalación.
* **Contraseña de admin local**: semáforo para el supervisor, avisos desde 10 días antes y bloqueo total a 5 días.
* **Avisos** en el inicio con fechas de publicación, y **tareas** con folio, fecha de entrega y evidencias por agente.
* **Correo a la mesa institucional** (`mesadeayuda@pemex.com`, configurable) por cada ticket nuevo, para Remedy.
* **Coordinadores por zona** (Zona Norte, Sur, Marina…): ven y supervisan las instalaciones de su zona y asignan sus supervisores.
* **Ficha del trabajador** para todo TI: dónde está, equipos a su cargo con su instalación, equipos que tuvo antes y sus tickets, aunque no haya activado su cuenta.
* **Inventario por instalación** y **cuentas de administración y de prueba** creadas por el Admin TI; folios de ticket y tarea no consecutivos (`TK-2026-483920`).
* **Carga semanal del GECA** (`/geca`): `.xlsx` o `.csv` (coma o punto y coma, UTF-8 o ANSI de Excel), reporte "Equipos entregados con estatus" (una fila por componente, monitores casados con su computadora), **UPSERT** por `ficha` y `serie` en una sola transacción, historial de cargas, detalle con advertencias y plantilla descargable.

Rendimiento medido con el GECA real: 124 317 filas (21 MB .xlsx) en ~40 s usando ~145 MB de RAM.

## Requisitos del hosting

| Requisito | Dónde se configura en cPanel |
|---|---|
| PHP **8.3** o superior | *MultiPHP Manager* (o *Select PHP Version*) |
| Extensiones `pdo_mysql`, `mbstring`, `zip`, `xmlreader` | *Select PHP Version* > *Extensions* (vienen activas normalmente) |
| `upload_max_filesize` / `post_max_size` ≥ tamaño del GECA | `.user.ini` incluido (32 MB) o *MultiPHP INI Editor* |
| MySQL 5.7+ o MariaDB 10.3+ | *MySQL® Databases* |

## Despliegue en NEUBOX (paso a paso)

La instalación se hace con un **asistente web**: no necesitas SSH, phpMyAdmin ni editar archivos.

1. **Crea la base de datos** (único paso manual; cPanel no permite hacerlo desde PHP):
   1. cPanel › *MySQL® Databases* › *Create New Database*: `mesaayuda` (quedará `usuario_mesaayuda`).
   2. *Add New User*: `tickets` (quedará `usuario_tickets`) con una contraseña generada.
   3. *Add User To Database*: elige ambos y marca **ALL PRIVILEGES**.
2. **Sube los archivos**:
   1. Comprime el repositorio en `.zip` (sin la carpeta `.git`).
   2. *Administrador de archivos* › entra a `public_html/` (o a la carpeta del subdominio) › *Cargar* › *Extraer*.
   3. Activa *Configuración › Mostrar archivos ocultos* y confirma que `.htaccess` y `.user.ini` quedaron en la raíz.
3. **(Recomendado) Activa SSL** en cPanel › *SSL/TLS Status* › *Run AutoSSL*, para instalar ya por `https://`.
4. **Abre tu dominio** en el navegador: te llevará solo a `/instalacion`.
   1. **Requisitos**: el asistente revisa PHP, extensiones, límites, permisos, `mod_rewrite`, HTTPS y que `/config`, `/database` y `/storage` respondan 403.
   2. **Clave de instalación**: abre `storage/install.key` en el Administrador de archivos y cópiala. Así nadie más puede instalar el sistema antes que tú.
   3. **Base de datos**: captura los datos del paso 1. Se prueba la conexión, la versión del servidor, InnoDB y los privilegios reales.
   4. **Sitio**: nombre, URL (autodetectada), zona horaria y, si entraste por https, *Forzar HTTPS*.
   5. **Administrador**: ficha, nombre, correo y contraseña (mín. 12 caracteres).
   6. **Instalar**: crea las tablas, el admin y `config/config.php`, y bloquea el asistente.

Si el servidor no deja escribir `config/config.php`, la pantalla final te permite **descargarlo** para subirlo a `config/` con el Administrador de archivos.

### Qué protege el instalador

* Solo funciona mientras **no exista** `config/config.php`; después responde 404.
* Exige la clave privada de `storage/install.key` (bloqueada por web), que se borra al terminar.
* Si la creación de tablas falla a medias, **elimina solo las tablas que acaba de crear**; nunca borra datos existentes.
* Si la base ya tiene todas las tablas (reinstalación), las conserva con sus datos.
* Si la base tiene solo algunas tablas del sistema, se detiene y pide revisarla.
* La contraseña del administrador se guarda ya cifrada (`password_hash`) desde el paso 4; `config.php` se escribe de forma atómica, se valida antes de activarse y queda con permisos `640`.

### Instalación manual (alternativa)

Importa `database/schema.sql` en phpMyAdmin, copia `config/config.example.php` como `config/config.php` con tus credenciales y abre `/instalar` para crear el primer Admin TI (se desactiva solo al existir uno).

### Actualizar una instalación existente (de la Fase 4 a la Fase 5)

1. **Respalda** la base (phpMyAdmin › Exportar) y los archivos (Administrador de archivos › Comprimir).
2. phpMyAdmin › tu base › **Importar** › `database/migraciones/2026-11-23_fase5.sql` (una sola vez).
3. Sube y extrae el zip de cambios sobre la carpeta del sistema (sobrescribe).
4. En `config/config.php` agrega dentro de `'mail' => [...]` la línea `'mesa_institucional' => 'mesadeayuda@pemex.com',` y, al final del arreglo, la sección `'registro' => ['dominios_permitidos' => ['pemex.com']],` (si faltan, el sistema usa esos mismos valores por defecto).
5. Crea la **Tarea Cron** (abajo) y prueba el correo antes de avisar a los usuarios: todas las cuentas deberán **verificar su correo** y el personal de TI **registrar la fecha de su contraseña de admin local** en su siguiente ingreso.
6. Como Admin TI: *Gestión › Instalaciones* (crear instalaciones, asignar supervisores y llenar las equivalencias GECA). Cada supervisor: *Supervisión › Ubicaciones* y *Agentes*.

### Actualizaciones posteriores a la Fase 5

Importa en phpMyAdmin, **en este orden y una sola vez cada uno**, los que aún no hayas aplicado (ver `database/migraciones/LEEME.md`):

1. `2026-11-23_geca_componentes.sql` — GECA por componente.
2. `2026-11-24_avisos_imagen.sql` — noticias con imagen en la entrada.
3. `2026-11-24_coordinadores_cuentas.sql` — coordinadores por zona y cuentas de administración/prueba.

Después, como Admin TI: *Usuarios › Crear cuenta* (por ejemplo `ADMIN-TI` de tipo *Administración*) y *Gestión › Zonas*.

### Tarea Cron (recordatorios y tiempos de atención)

cPanel › **Cron Jobs** › *Common Settings: Once Per Hour* (cada hora, para que los avisos de tickets fuera de tiempo salgan a tiempo; los recordatorios diarios no se repiten) › Command:

```
/usr/local/bin/php /home/USUARIO/public_html/bin/cron.php
```

Ajusta la ruta a donde está el sistema. Si el hosting no permitiera Cron, la primera visita del día hace la revisión automáticamente.

### Correo (avisos automáticos)

El instalador deja activo el envío con la función `mail()` del hosting, que funciona sin configurar nada pero puede caer en spam. Para mejor entrega:

1. cPanel › *Email Accounts* › crea `no-responder@tudominio.com`.
2. Edita `config/config.php` (Administrador de archivos › *Edit*). En la sección `mail` pon `'driver' => 'smtp'` y la contraseña de esa cuenta en `smtp.password`.
3. En *Gestión › Usuarios* pulsa **Enviarme un correo de prueba**.

Para que los avisos de tickets nuevos lleguen a un buzón del área en lugar de a cada agente, llena `notify_new_tickets_to`.

### Si algo falla

* **Error 500 inmediato**: algunos planes no permiten `Options` en `.htaccess`; comenta la línea `Options -Indexes -MultiViews` y desactiva *Indexes* desde cPanel > *Índices*.
* **Las rutas dan 404 en subcarpeta**: descomenta y ajusta `RewriteBase /subcarpeta/` en `.htaccess`.
* **“No fue posible conectar con la base de datos”**: revisa el prefijo `usuario_` en base y usuario, y que el usuario esté asignado a la base.
* **Errores detallados**: `storage/logs/php-error.log` (descárgalo desde el Administrador de archivos).

## Carga semanal del GECA

* Menú **Carga GECA** (roles `agente_ti` y `admin_ti`) > elige el archivo > *Procesar archivo*.
* Archivo: el reporte **"Equipos entregados con estatus"** del GECA tal cual (una fila por componente; columnas en `/geca/plantilla`); se toleran acentos, mayúsculas y espacios en los encabezados.
* Formatos: `.xlsx` (Excel 2007+) y `.csv`. Los `.xls` antiguos se deben guardar antes como `.xlsx` o CSV.
* Qué hace: crea el registro en `cargas_geca`, marca todo el inventario como no vigente, casa cada monitor con la computadora de su folio (o con la única laptop libre de la ficha), y por cada fila inserta/recupera la ubicación, hace UPSERT del usuario (solo nombre y jefe) y del equipo. Replicadores y proyectores son equipos propios; los resguardos en BAJA quedan sin trabajador. En equipos existentes **no se tocan** la ubicación real, el equipo principal, garantías ni tickets. Si algo falla, nada cambia.

## Desarrollo local

```bash
cp config/config.example.php config/config.php
```

```bash
php -S localhost:8080 index.php
```

(El servidor embebido de PHP ignora `.htaccess`; para probar las reglas de bloqueo usa Apache con `AllowOverride All`.)
