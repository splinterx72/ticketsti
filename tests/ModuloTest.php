<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\ModuloService;

function test_modulos_por_omision_respetan_los_permisos_de_siempre(): void
{
    $m = new ModuloService(test_pdo());
    assert_true($m->permite('garantias', 'usuario'), 'el trabajador ve el historial de garantías');
    assert_true($m->permite('garantias', 'agente_ti'), 'el agente gestiona garantías');
    assert_true(!$m->permite('reportes', 'usuario'), 'el trabajador no ve reportes');
    assert_true(!$m->permite('avisos', 'agente_ti'), 'el agente no publica avisos');
    assert_true($m->permite('avisos', 'supervisor'), 'el supervisor publica avisos');
    assert_true(!$m->permite('no-existe', 'agente_ti'), 'módulo desconocido: no');
    assert_true($m->permite('reportes', 'admin_ti'), 'admin siempre');
}

function test_admin_apaga_modulos_y_cambia_roles(): void
{
    $m = new ModuloService(test_pdo());
    $m->guardar('reportes', false, ['agente_ti', 'supervisor']);
    assert_true(!$m->permite('reportes', 'supervisor'), 'apagado: nadie (salvo admin)');
    assert_true($m->permite('reportes', 'admin_ti'), 'el admin conserva el acceso para volver a encenderlo');

    $m->guardar('reportes', true, ['supervisor', 'rol-inventado', 'admin_ti']);
    assert_true($m->permite('reportes', 'supervisor'), 'encendido para supervisor');
    assert_true(!$m->permite('reportes', 'agente_ti'), 'ya no para agente');
    assert_eq(scalar("SELECT roles FROM modulos WHERE clave = 'reportes'"), 'supervisor', 'solo roles válidos, sin admin (siempre implícito)');
    assert_throws(ValidationException::class, fn () => $m->guardar('no-existe', true, []), 'módulo desconocido');
}

function test_lista_para_la_pantalla_del_admin(): void
{
    $lista = (new ModuloService(test_pdo()))->todos();
    assert_eq(array_column($lista, 'clave'), ['avisos', 'bitlocker', 'formatos', 'garantias', 'inventario', 'plantillas', 'reportes', 'resguardo', 'tareas'], 'los nueve módulos');
    assert_eq($lista[3]['roles'], ['usuario', 'agente_ti', 'supervisor', 'coordinador'], 'roles como lista');
}

function test_sin_tabla_de_modulos_se_conservan_los_permisos_de_siempre(): void
{
    test_pdo()->exec('DROP TABLE modulos');
    ModuloService::olvidar();
    $m = new ModuloService(test_pdo());
    assert_true($m->permite('tareas', 'agente_ti') && !$m->permite('avisos', 'agente_ti'), 'valores por omisión mientras no se importe la migración');
}

function test_solo_se_ofrecen_los_roles_posibles_de_cada_modulo(): void
{
    $m = new ModuloService(test_pdo());
    $m->guardar('avisos', true, ['agente_ti', 'usuario', 'supervisor']);
    assert_eq(scalar("SELECT roles FROM modulos WHERE clave = 'avisos'"), 'supervisor', 'publicar avisos no aplica a agentes ni trabajadores');
    assert_eq(ModuloService::POSIBLES['garantias'], ['usuario', 'agente_ti', 'supervisor', 'coordinador'], 'garantías aplica a todos');
}
