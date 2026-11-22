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

## 4. Esquema de Base de Datos (MariaDB / MySQL)

```sql
CREATE DATABASE IF NOT EXISTS mesa_ayuda_ti
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mesa_ayuda_ti;

CREATE TABLE ubicaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    eps VARCHAR(80) NOT NULL,
    subdireccion VARCHAR(120) NOT NULL,
    gerencia VARCHAR(120) NOT NULL,
    zona VARCHAR(100) NULL,
    sector VARCHAR(100) NULL,
    inmueble VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_ubicacion_completa (eps, subdireccion, gerencia, zona, sector, inmueble)
) ENGINE=InnoDB;

CREATE TABLE cargas_geca (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    usuario_ti_id INT UNSIGNED NULL,
    total_registros INT UNSIGNED NOT NULL DEFAULT 0,
    nuevos_insertados INT UNSIGNED NOT NULL DEFAULT 0,
    actualizados INT UNSIGNED NOT NULL DEFAULT 0,
    marcados_no_vigentes INT UNSIGNED NOT NULL DEFAULT 0,
    fecha_carga TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ficha VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    jefe_nombre VARCHAR(150) NULL,
    email VARCHAR(150) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    telefono_ext VARCHAR(30) NULL,
    ubicacion_habitual_id INT UNSIGNED NULL,
    rol ENUM('usuario', 'agente_ti', 'admin_ti') NOT NULL DEFAULT 'usuario',
    registrado TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuario_ubicacion
        FOREIGN KEY (ubicacion_habitual_id) REFERENCES ubicaciones(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE equipos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    serie VARCHAR(60) NOT NULL UNIQUE,
    usuario_id INT UNSIGNED NULL,
    ubicacion_geca_id INT UNSIGNED NULL,
    ubicacion_real_id INT UNSIGNED NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    folio_resguardo VARCHAR(40) NULL,
    fecha_resguardo DATE NULL,
    estado_resguardo VARCHAR(60) NULL,
    numero_contrato VARCHAR(60) NULL,
    estado_inventario VARCHAR(60) NULL,
    marca VARCHAR(60) NULL,
    modelo VARCHAR(80) NULL,
    perfil VARCHAR(80) NULL,
    usuario_fecha_aceptacion DATETIME NULL,
    ti_fecha_validacion DATETIME NULL,
    fecha_baja DATETIME NULL,
    fecha_carga_resguardo DATETIME NULL,
    fuera_consola VARCHAR(40) NULL,
    motivo_baja VARCHAR(255) NULL,
    serie_monitor1 VARCHAR(60) NULL,
    marca_monitor1 VARCHAR(60) NULL,
    modelo_monitor1 VARCHAR(80) NULL,
    serie_monitor2 VARCHAR(60) NULL,
    marca_monitor2 VARCHAR(60) NULL,
    modelo_monitor2 VARCHAR(80) NULL,
    ultima_carga_geca_id INT UNSIGNED NULL,
    vigente_en_geca TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_geca FOREIGN KEY (ubicacion_geca_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_ubicacion_real FOREIGN KEY (ubicacion_real_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    CONSTRAINT fk_equipo_carga_geca FOREIGN KEY (ultima_carga_geca_id) REFERENCES cargas_geca(id) ON DELETE SET NULL,
    INDEX idx_equipo_usuario (usuario_id),
    INDEX idx_equipo_monitor1 (serie_monitor1),
    INDEX idx_equipo_monitor2 (serie_monitor2)
) ENGINE=InnoDB;

CREATE TABLE garantias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipo_id INT UNSIGNED NOT NULL,
    elemento_cubierto ENUM('equipo', 'monitor_1', 'monitor_2') NOT NULL DEFAULT 'equipo',
    proveedor VARCHAR(120) NOT NULL,
    numero_poliza_contrato VARCHAR(80) NULL,
    fecha_inicio DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    estado ENUM('vigente', 'en_reclamacion', 'vencida') NOT NULL DEFAULT 'vigente',
    notas TEXT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_garantia_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE CASCADE,
    INDEX idx_garantia_vencimiento (fecha_vencimiento, estado)
) ENGINE=InnoDB;

CREATE TABLE departamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE estados (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    color_hex CHAR(7) NOT NULL DEFAULT '#6c757d',
    es_cierre TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(25) NOT NULL UNIQUE,
    tipo_ticket ENUM('incidencia', 'solicitud', 'actualizacion_ubicacion', 'garantia') NOT NULL DEFAULT 'incidencia',
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NOT NULL,
    prioridad ENUM('baja', 'media', 'alta', 'critica') NOT NULL DEFAULT 'media',
    usuario_solicitante_id INT UNSIGNED NOT NULL,
    agente_asignado_id INT UNSIGNED NULL,
    departamento_id INT UNSIGNED NOT NULL,
    estado_id TINYINT UNSIGNED NOT NULL,
    equipo_id INT UNSIGNED NULL,
    componente_afectado ENUM('equipo', 'monitor_1', 'monitor_2', 'general') NOT NULL DEFAULT 'general',
    hostname_reportado VARCHAR(30) NULL,
    ubicacion_reportada_id INT UNSIGNED NULL,
    discrepancia_ubicacion TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cerrado_en TIMESTAMP NULL,
    CONSTRAINT fk_ticket_solicitante FOREIGN KEY (usuario_solicitante_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_agente FOREIGN KEY (agente_asignado_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_depto FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_estado FOREIGN KEY (estado_id) REFERENCES estados(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_equipo FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_ubicacion FOREIGN KEY (ubicacion_reportada_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    INDEX idx_tickets_tablero (estado_id, departamento_id),
    INDEX idx_tickets_discrepancia (discrepancia_ubicacion)
) ENGINE=InnoDB;

CREATE TABLE comentarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    mensaje TEXT NOT NULL,
    es_nota_interna TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comentario_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_comentario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Datos semilla iniciales
INSERT INTO estados (nombre, color_hex, es_cierre) VALUES
('Abierto', '#0d6efd', 0),
('En proceso', '#ffc107', 0),
('En espera de usuario', '#fd7e14', 0),
('Resuelto', '#198754', 1),
('Cerrado', '#6c757d', 1);

INSERT INTO departamentos (nombre, descripcion) VALUES
('Soporte Técnico en Sitio', 'Atención a equipos de cómputo, monitores y periféricos'),
('Control de Resguardos GECA', 'Actualización de ubicaciones y validación de inventario'),
('Garantías y Proveedores', 'Seguimiento de pólizas y garantías de hardware');
