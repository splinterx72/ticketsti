# ticketsti — Mesa de Ayuda TI y Control de Resguardos (GECA)

Sistema web ligero de tickets de soporte técnico integrado con el inventario oficial de resguardos **GECA**, diseñado para correr en **hosting compartido cPanel (NEUBOX)**: PHP 8.3+, MySQL/MariaDB y Apache, sin Docker, sin SSH y sin Composer.

> Reglas de negocio, convenciones y estado por fases: ver [`CLAUDE.md`](CLAUDE.md).
> Esquema de base de datos: [`database/schema.sql`](database/schema.sql).

## Funcionalidad actual (Fases 1 y 2)

* Front Controller con URLs amigables (`.htaccess` + `index.php`), funciona en la raíz o en una subcarpeta.
* Carpetas internas (`src/`, `config/`, `database/`, `storage/`) y archivos sensibles (`.env`, `.sql`, `.md`, `.log`…) bloqueados por web.
* Inicio de sesión por ficha o correo, sesiones aisladas en `storage/sessions`, CSRF en todos los formularios y bloqueo temporal tras intentos fallidos.
* Asistente `/instalar` para crear el primer **Admin TI** (se desactiva solo).
* **Carga semanal del GECA** (`/geca`): `.xlsx` o `.csv` (coma o punto y coma, UTF-8 o ANSI de Excel), validación de los 30 encabezados, **UPSERT** por `ficha` y `serie` en una sola transacción, historial de cargas, detalle con advertencias y plantilla descargable.

Rendimiento medido: 30 000 filas en ~5 s (CSV) / ~8 s (XLSX) usando 4–14 MB de RAM.

## Requisitos del hosting

| Requisito | Dónde se configura en cPanel |
|---|---|
| PHP **8.3** o superior | *MultiPHP Manager* (o *Select PHP Version*) |
| Extensiones `pdo_mysql`, `mbstring`, `zip`, `xmlreader` | *Select PHP Version* > *Extensions* (vienen activas normalmente) |
| `upload_max_filesize` / `post_max_size` ≥ tamaño del GECA | `.user.ini` incluido (32 MB) o *MultiPHP INI Editor* |
| MySQL 5.7+ o MariaDB 10.3+ | *MySQL® Databases* |

## Despliegue en NEUBOX (paso a paso)

1. **Base de datos**
   1. cPanel > *MySQL® Databases*: crea la base (ej. `mesaayuda` → queda `usuario_mesaayuda`).
   2. Crea un usuario (ej. `tickets` → `usuario_tickets`) con contraseña robusta.
   3. *Add User To Database* con **ALL PRIVILEGES**.
   4. *phpMyAdmin* > selecciona la base > pestaña **Importar** > `database/schema.sql` > *Continuar*.
2. **Archivos**
   1. Comprime el repositorio en `.zip` (sin la carpeta `.git`).
   2. *Administrador de archivos* > entra a `public_html/` (o a la carpeta del subdominio, ej. `tickets.tudominio.com/`) > *Cargar* el zip > *Extraer*.
   3. Activa *Configuración* > *Mostrar archivos ocultos* y confirma que `.htaccess` y `.user.ini` quedaron en la raíz.
3. **Configuración**
   1. Copia `config/config.example.php` como `config/config.php`.
   2. Edita host (`localhost`), base, usuario y contraseña con prefijo de cPanel, y `base_url`.
4. **Permisos**: carpetas `755`, archivos `644`; `storage/uploads`, `storage/logs` y `storage/sessions` deben ser escribibles (755 basta en cPanel con suPHP/PHP-FPM).
5. **Primer administrador**: abre `https://tu-dominio/instalar`, captura ficha, nombre, correo y contraseña. Después pon `'install_enabled' => false` en `config/config.php`.
6. **SSL**: cuando AutoSSL esté activo, descomenta el bloque *Forzar HTTPS* del `.htaccess`.
7. **Verificación rápida**: `https://tu-dominio/config/config.php`, `/database/schema.sql` y `/src/` deben responder **403**.

### Si algo falla

* **Error 500 inmediato**: algunos planes no permiten `Options` en `.htaccess`; comenta la línea `Options -Indexes -MultiViews` y desactiva *Indexes* desde cPanel > *Índices*.
* **Las rutas dan 404 en subcarpeta**: descomenta y ajusta `RewriteBase /subcarpeta/` en `.htaccess`.
* **“No fue posible conectar con la base de datos”**: revisa el prefijo `usuario_` en base y usuario, y que el usuario esté asignado a la base.
* **Errores detallados**: `storage/logs/php-error.log` (descárgalo desde el Administrador de archivos).

## Carga semanal del GECA

* Menú **Carga GECA** (roles `agente_ti` y `admin_ti`) > elige el archivo > *Procesar archivo*.
* Encabezados: los 30 de la plantilla (`/geca/plantilla`); se toleran acentos, mayúsculas y espacios.
* Formatos: `.xlsx` (Excel 2007+) y `.csv`. Los `.xls` antiguos se deben guardar antes como `.xlsx` o CSV.
* Qué hace: crea el registro en `cargas_geca`, marca todo el inventario como no vigente, y por cada fila inserta/recupera la ubicación, hace UPSERT del usuario (solo `nombre` y `jefe_nombre`) y del equipo. En equipos existentes **no se tocan** `ubicacion_real_id`, `es_principal`, garantías ni tickets. Si algo falla, nada cambia.

## Desarrollo local

```bash
cp config/config.example.php config/config.php
```

```bash
php -S localhost:8080 index.php
```

(El servidor embebido de PHP ignora `.htaccess`; para probar las reglas de bloqueo usa Apache con `AllowOverride All`.)
