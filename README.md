# ticketsti
Solucion de requerimientos de los clientes, para una administracion de los soportes otorgados
# Sistema de Mesa de Ayuda TI y Control de Resguardos (GECA)

## 1. Descripción General del Proyecto
Sistema web ligero de tickets de soporte técnico integrado con el inventario oficial de resguardos (**GECA**). Permite a los trabajadores registrarse validando su **ficha**, levantar tickets asociados a su equipo principal, monitores o incidencias generales, detectar discrepancias de ubicación física frente al resguardo oficial y gestionar garantías de hardware.

---

## 2. Stack Tecnológico (VPS Linux / Administración por SSH)
* **Servidor / Despliegue:** VPS Ubuntu con Docker y Docker Compose (administrado vía SSH y Git).
* **Servidor Web:** Nginx.
* **Backend:** PHP 8.3 / 8.4 (arquitectura MVC limpia o modular sin dependencias pesadas, PDO con sentencias preparadas).
* **Base de Datos:** MariaDB 11.8 LTS (`InnoDB`, `utf8mb4_unicode_ci`).
* **Frontend:** HTML5, CSS3, Bootstrap 5 / Tabler UI (responsivo, limpio y rápido) + JavaScript (Fetch API).
* **Procesamiento de Archivos:** `PhpSpreadsheet` (para importar los archivos `.xlsx` / `.csv` semanales del GECA).

---

## 3. Reglas de Negocio Críticas

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
* Si existe, muestra su nombre y jefe para confirmación; el usuario captura su `email`, `telefono_ext`, `contraseña` y selecciona su `ubicacion_habitual_id`, cambiando su estado a `registrado = 1`.
* **Roles:** `usuario` (solicitante), `agente_ti` (técnico que atiende y sube GECA), `admin_ti` (gestión total).

### C. Creación de Tickets y Hostname
* Al abrir el formulario de nuevo ticket, el sistema carga los equipos asignados al usuario ordenados por `es_principal DESC` (preseleccionando su equipo principal, permitiendo cambiar a otro equipo, a un monitor casado o a **"Sin equipo / Incidencia general"**).
* **Hostname manual:** El usuario o técnico puede capturar el hostname completo (prefijo de 5 caracteres que inicia con `PM...`, ej. `PMPPP` + `serie`) en el campo `hostname_reportado`.
* **Componente afectado:** Permite especificar si la falla es en `equipo`, `monitor_1`, `monitor_2` o `general`.

### D. Control de Ubicaciones (Oficial GECA vs. Real Física)
* Un usuario puede tener equipos en ubicaciones distintas; por ello la ubicación vive a nivel de cada equipo.
* Al crear un ticket, el usuario confirma en qué ubicación se encuentra físicamente el equipo (`ubicacion_reportada_id`).
* Si `ubicacion_reportada_id != equipos.ubicacion_geca_id`:
  1. Se actualiza `equipos.ubicacion_real_id` con la nueva ubicación física.
  2. Se marca el ticket con `discrepancia_ubicacion = 1`.
  3. El sistema alerta a TI (o genera un ticket vinculado de tipo `actualizacion_ubicacion`) para tramitar el cambio de resguardo en GECA.

### E. Gestión de Garantías
* Módulo vinculado a `equipos` que permite registrar pólizas/contratos indicando si cubren el `equipo`, `monitor_1` o `monitor_2`, con fechas de vigencia, proveedor y estado (`vigente`, `en_reclamacion`, `vencida`).

---
INSERT INTO departamentos (nombre, descripcion) VALUES
('Soporte Técnico en Sitio', 'Atención a equipos de cómputo, monitores y periféricos'),
('Control de Resguardos GECA', 'Actualización de ubicaciones y validación de inventario'),
('Garantías y Proveedores', 'Seguimiento de pólizas y garantías de hardware');
