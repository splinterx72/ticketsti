<?php

declare(strict_types=1);

/**
 * Toda ruta pasa por requireLogin()/requireRole() (que aplican la verificación
 * de correo y el bloqueo total por admin local), salvo las de invitado listadas.
 */
const RUTAS_SIN_SESION = [
    'InstallerController' => '*',   // asistente /instalacion (solo sin config.php)
    'InstallController'   => '*',   // /instalar: primer Admin TI (se desactiva al existir uno)
    'AuthController'      => ['showLogin', 'login', 'logout'],
    'RegistroController'  => '*',
    'VerificacionController' => '*',
    'AdminLocalController'   => '*',
    'AvisoController'        => ['imagen'],   // pública solo para avisos "para todos" vigentes (AvisoService::imagenVisible)
];

/** @return list<array{0: string, 1: string, 2: string}> [ruta, controlador, método] */
function rutas_de_index(): array
{
    preg_match_all("/\\\$router->(?:get|post)\\('([^']+)',\\s*\\[(\\w+)::class,\\s*'(\\w+)'\\]\\)/", (string) file_get_contents(ROOT_PATH . '/index.php'), $m, PREG_SET_ORDER);
    return array_map(static fn (array $r): array => [$r[1], $r[2], $r[3]], $m);
}

/** Código del método y de los métodos privados de la clase que invoca (un nivel). */
function codigo_accion(string $clase, string $metodo): string
{
    $ref = new ReflectionMethod('App\\Controllers\\' . $clase, $metodo);
    $lineas = file((string) $ref->getFileName());
    $codigo = implode('', array_slice($lineas, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    preg_match_all('/\$this->(\w+)\(/', $codigo, $llamadas);
    foreach (array_unique($llamadas[1]) as $m) {
        if (method_exists('App\\Controllers\\' . $clase, $m) && !in_array($m, ['requireLogin', 'requireRole'], true)) {
            $r = new ReflectionMethod('App\\Controllers\\' . $clase, $m);
            if ($r->getDeclaringClass()->getName() === 'App\\Controllers\\' . $clase) {
                $codigo .= implode('', array_slice($lineas, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
            }
        }
    }
    return $codigo;
}

function test_toda_ruta_privada_exige_sesion_y_respeta_bloqueo(): void
{
    $rutas = rutas_de_index();
    assert_true(count($rutas) > 80, 'se leyeron las rutas de index.php');
    foreach ($rutas as [$ruta, $clase, $metodo]) {
        $libre = RUTAS_SIN_SESION[$clase] ?? [];
        if ($libre === '*' || in_array($metodo, (array) $libre, true)) {
            continue;
        }
        $codigo = codigo_accion($clase, $metodo);
        assert_true(preg_match('/\$this->require(Login|Role)\(/', $codigo) === 1, "{$ruta} ({$clase}::{$metodo}) no pasa por requireLogin/requireRole");
    }
}

function test_post_con_csrf(): void
{
    preg_match_all("/\\\$router->post\\('([^']+)',\\s*\\[(\\w+)::class,\\s*'(\\w+)'\\]\\)/", (string) file_get_contents(ROOT_PATH . '/index.php'), $m, PREG_SET_ORDER);
    foreach ($m as [, $ruta, $clase, $metodo]) {
        assert_true(str_contains(codigo_accion($clase, $metodo), 'verifyCsrf()'), "POST {$ruta} ({$clase}::{$metodo}) sin verifyCsrf()");
    }
}
