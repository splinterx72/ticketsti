<?php

declare(strict_types=1);

use App\Services\Installer\Installer;

const FASE5_TABLAS = [
    'instalaciones', 'ubicacion_tipos', 'niveles', 'ubicaciones_fisicas', 'ubicacion_fisica_niveles',
    'ubicacion_fisica_alas', 'geca_inmueble_instalacion', 'supervisor_instalaciones', 'agente_instalaciones',
    'avisos', 'aviso_instalaciones', 'tareas', 'tarea_asignaciones', 'tarea_archivos',
    'recordatorios_enviados', 'sistema_estado', 'verificaciones_email',
];

/** @return array<string, string> "tabla.columna" => "COLUMN_TYPE|IS_NULLABLE" */
function columnas(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, COLUMN_NAME"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = $r['COLUMN_TYPE'] . '|' . $r['IS_NULLABLE'];
    }
    return $out;
}

function schema_fase4(): string
{
    $sql = shell_exec('git -C ' . escapeshellarg(ROOT_PATH) . ' show 917ef12:database/schema.sql');
    if (!is_string($sql) || $sql === '') {
        throw new RuntimeException('No se pudo leer el schema de la Fase 4 desde git.');
    }
    return $sql;
}

function test_schema_nuevo_tiene_tablas_fase5(): void
{
    assert_eq(count(Installer::TABLES), 26, 'Installer::TABLES tiene 26 tablas');
    foreach (FASE5_TABLAS as $t) {
        assert_true(in_array($t, Installer::TABLES, true), "Installer::TABLES incluye {$t}");
    }
    $existentes = test_pdo()->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach (Installer::TABLES as $t) {
        assert_true(in_array($t, $existentes, true), "schema.sql crea {$t}");
    }
    $cols = columnas(test_pdo());
    foreach ([
        'usuarios.email_verificado_en', 'usuarios.admin_local_cambiado_en', 'usuarios.habitual_ubicacion_fisica_id',
        'usuarios.habitual_nivel_id', 'usuarios.habitual_ala', 'usuarios.habitual_referencia',
        'equipos.real_ubicacion_fisica_id', 'equipos.real_nivel_id', 'equipos.real_ala',
        'tickets.instalacion_id', 'tickets.reportada_ubicacion_fisica_id', 'tickets.reportada_nivel_id',
        'tickets.reportada_ala', 'tickets.referencia',
    ] as $c) {
        assert_true(isset($cols[$c]), "existe columna {$c}");
    }
    assert_true(str_contains($cols['usuarios.rol'] ?? '', "'supervisor'"), 'usuarios.rol admite supervisor');
    assert_eq(scalar("SELECT COUNT(*) FROM niveles WHERE nombre IN ('Único','Sótano','PB','Mezanine','1','10')"), 6, 'semilla de niveles');
    assert_eq(scalar('SELECT COUNT(*) FROM niveles'), 14, 'niveles = Único, Sótano, PB, 1–10, Mezanine');
    assert_eq(scalar('SELECT COUNT(*) FROM ubicacion_tipos'), 6, 'tipos de ubicación sembrados');
}

function test_migracion_iguala_schema(): void
{
    $nuevo = columnas(test_pdo());

    $old = fresh_db('usr_mesa_test_mig');
    import_sql($old, schema_fase4());
    $old->exec("INSERT INTO usuarios (ficha, nombre, email, password_hash, rol, registrado) VALUES ('12345', 'José Peña', 'jose@pemex.com', 'x', 'agente_ti', 1)");
    $old->exec("INSERT INTO equipos (serie, usuario_id) VALUES ('SER001', 1)");

    import_sql($old, (string) file_get_contents(ROOT_PATH . '/database/migraciones/2026-11-23_fase5.sql'));

    $migrado = columnas($old);
    assert_eq(array_diff_key($nuevo, $migrado), [], 'columnas que faltan tras migrar');
    assert_eq(array_diff_key($migrado, $nuevo), [], 'columnas sobrantes tras migrar');
    foreach ($nuevo as $k => $v) {
        if (isset($migrado[$k]) && $migrado[$k] !== $v) {
            assert_eq($migrado[$k], $v, "tipo de {$k}");
        }
    }
    assert_eq($old->query("SELECT CONCAT(ficha,'|',rol,'|',registrado) FROM usuarios")->fetchColumn(), '12345|agente_ti|1', 'usuario conservado');
    assert_eq($old->query('SELECT email_verificado_en FROM usuarios')->fetchColumn(), null, 'cuenta existente queda sin verificar');
    assert_eq($old->query("SELECT serie FROM equipos")->fetchColumn(), 'SER001', 'equipo conservado');
    assert_eq((int) $old->query('SELECT COUNT(*) FROM niveles')->fetchColumn(), 14, 'migración siembra niveles');
}

function test_migracion_no_deja_sin_acceso_al_admin_existente(): void
{
    $old = fresh_db('usr_mesa_test_mig');
    import_sql($old, schema_fase4());
    $old->exec("INSERT INTO usuarios (ficha, nombre, email, password_hash, rol, registrado, activo) VALUES
        ('900001', 'Admin', 'admin@pemex.com', 'x', 'admin_ti', 1, 1),
        ('12345', 'José Peña', 'jose@pemex.com', 'x', 'usuario', 1, 1)");

    import_sql($old, (string) file_get_contents(ROOT_PATH . '/database/migraciones/2026-11-23_fase5.sql'));

    $v = $old->query("SELECT ficha, email_verificado_en IS NOT NULL FROM usuarios ORDER BY ficha")->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_eq((int) $v['900001'], 1, 'el admin_ti existente queda verificado (si el correo falla no habría quien lo desbloquee)');
    assert_eq((int) $v['12345'], 0, 'los demás deben verificar su correo');
}

function test_installer_reconoce_reinstalacion(): void
{
    $installer = new Installer(ROOT_PATH);
    $nuevo = $installer->inspectDatabase(test_db_config());
    assert_eq($nuevo['mode'], 'reuse', 'schema nuevo = reinstalación');

    $old = fresh_db('usr_mesa_test_mig');
    import_sql($old, schema_fase4());
    $viejo = $installer->inspectDatabase(test_db_config('usr_mesa_test_mig'));
    assert_eq($viejo['mode'], 'partial', 'schema de Fase 4 = parcial (requiere migración)');
}
