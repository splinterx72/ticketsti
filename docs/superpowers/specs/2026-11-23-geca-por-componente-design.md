# GECA por componente — diseño

Fecha: 2026-11-23. Sustituye el formato de 30 columnas (una fila por equipo con
monitores en columnas) por el reporte oficial "Equipos entregados con estatus"
(una fila por componente, 40 columnas). El formato anterior deja de aceptarse.

## Archivo de origen (medido sobre el reporte real del 18/11/2026)

* 124 317 filas, 40 columnas, 21 MB .xlsx, una hoja ("Reporte").
* `tipo_componente`: MONITOR, CPU, LAPTOP, REPLICADOR, PC, PROYECTOR, CHROMEBOOK, MACBOOK.
* `serie` única en todo el archivo; cada `folio` pertenece a una sola ficha.
* Folios típicos: CPU+MONITOR, LAPTOP sola, MONITOR+REPLICADOR (la laptop va en otro folio),
  PC+MONITOR, hasta 2 monitores por folio.
* `estado_resguardo` BAJA en 10 617 filas (equipo devuelto, casi siempre DISPONIBLE).
* Fechas `dd/mm/aaaa hh:mm:ss a. m./p. m.` (ya soportadas).

## Decisiones del usuario

1. **Replicadores y proyectores** son equipos propios (lista del trabajador, tickets, garantías).
2. **Monitores**: se casan como monitor 1/2 de la computadora (CPU, PC, LAPTOP, CHROMEBOOK, MACBOOK)
   **del mismo folio**. Si el folio no trae computadora, se casan con la **única** computadora
   "libre" (sin monitores en su folio) de la misma ficha, siempre que sumen como máximo 2.
   En cualquier otro caso (varias computadoras libres, ninguna, más de 2 monitores) el monitor
   queda como **equipo propio**. Nunca se casa al azar.
3. **Resguardo en BAJA**: el equipo se guarda (inventario, tickets y garantías intactos) pero
   **sin usuario** (`usuario_id = NULL`): deja de aparecer como suyo. Esas filas no crean ni
   actualizan usuarios y no participan en el casado entre folios.
4. **Columnas nuevas** se guardan: tipo de componente, número de orden, lote, ficha del jefe,
   referencia de ubicación, entidad, municipio, tipo de asignación, motivo fuera de consola y el
   **resguardo anterior** (`pr_folio`, `pr_fecha`, `pr_estado_resguardo`, `pr_nombre`, `pr_ficha`,
   `pr_eps`, `pr_zona`). `dia`, `mes`, `año` se ignoran (repiten `fecha`).

## Reglas que no cambian (CLAUDE.md 3.A)

UPSERT por `ficha` y `serie`, sin TRUNCATE ni DELETE; una transacción con `GET_LOCK`; todo el
inventario pasa a `vigente_en_geca = 0` y lo que viene en el archivo vuelve a 1; en equipos
existentes se preservan `ubicacion_real_id`, `real_*`, `es_principal`, garantías y tickets.
Un monitor que antes era equipo propio y ahora queda casado pasa a no vigente (no se borra).

## Encabezados

* **Obligatorios**: folio, fecha, estado_resguardo, estado_inventario, numero_contrato, nombre,
  ficha, eps, subdireccion, gerencia, jefe_nombre, perfil, tipo_componente, serie, marca, modelo,
  zona, sector, inmueble, usuario_fecha_aceptacion, ti_fecha_validacion, fecha_baja.
* **Opcionales** (se guardan si vienen): numero_orden, jefe_ficha, lote, ubicacion_referencia,
  entidad_federativa, municipio, tipo_asignacion, motivo_fuera_consola y los `pr_*`.
* Columnas desconocidas se ignoran. La plantilla descargable lista las 40 del reporte.

## Procesamiento en dos pasadas (memoria acotada para hosting compartido, 256 MB)

1. **Pasada 1** (sin tocar la BD): por folio, la computadora y los monitores; por ficha, sus
   folios. Se calcula a qué computadora (y en qué posición) va cada monitor.
2. **Pasada 2** (en la transacción): UPSERT de ubicaciones, usuarios y equipos. Las computadoras
   llevan los datos de sus monitores casados; los monitores casados no se guardan como equipo.

## Esquema

`equipos`: + tipo_componente, numero_orden, lote, ubicacion_referencia, entidad_federativa,
municipio, tipo_asignacion, pr_folio, pr_fecha, pr_estado_resguardo, pr_nombre, pr_ficha,
pr_eps, pr_zona; `fuera_consola` se amplía a 120. `usuarios`: + jefe_ficha.
Migración `database/migraciones/2026-11-23_geca_componentes.sql`.

## Vistas

Inventario (lista y ficha del equipo) muestra el tipo, la referencia, el tipo de asignación y el
resguardo anterior; el selector de equipo del ticket muestra el tipo. Textos "30 encabezados"
pasan a describir el reporte nuevo.
