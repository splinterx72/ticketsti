<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AvisoService;
use App\Services\ImagenAvisoService;

function dir_avisos_prueba(): string
{
    $dir = sys_get_temp_dir() . '/mesa_avisos_' . getmypid();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function imagenes(): ImagenAvisoService
{
    return new ImagenAvisoService(dir_avisos_prueba(), false);
}

/** Imagen real generada con GD. @return array<string, mixed> como $_FILES['x'] */
function subida_imagen(int $ancho, int $alto, string $tipo = 'jpeg', string $extra = ''): array
{
    $img = imagecreatetruecolor($ancho, $alto);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 11, 93, 115));
    $tmp = tempnam(sys_get_temp_dir(), 'img');
    match ($tipo) {
        'png'  => imagepng($img, $tmp),
        'webp' => imagewebp($img, $tmp),
        default => imagejpeg($img, $tmp, 90),
    };
    if ($extra !== '') {
        file_put_contents($tmp, $extra, FILE_APPEND);
    }
    return ['name' => "foto.{$tipo}", 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => UPLOAD_ERR_OK];
}

function test_imagen_valida_se_guarda_reducida_y_sin_nombre_original(): void
{
    $ruta = imagenes()->guardar(subida_imagen(2400, 1350, 'png'));
    $abs = imagenes()->rutaAbsoluta($ruta);
    assert_true($abs !== null && is_file($abs), 'la imagen quedó guardada');
    assert_true(!str_contains($ruta, 'foto'), 'el nombre guardado no usa el original');
    [$w, $h] = getimagesize((string) $abs);
    assert_eq([$w, $h], [1920, 1080], 'se reduce a máximo 1920 px conservando 16:9');
    assert_eq(mime_content_type((string) $abs), 'image/jpeg', 'se vuelve a codificar como JPEG');
}

function test_imagen_pequena_o_que_no_es_imagen_se_rechaza(): void
{
    assert_throws(ValidationException::class, fn () => imagenes()->guardar(subida_imagen(800, 450)), 'menos de 960×540', '960');
    $falsa = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($falsa, '<?php echo "hola"; ?>');
    assert_throws(ValidationException::class, fn () => imagenes()->guardar(['name' => 'x.jpg', 'tmp_name' => $falsa, 'size' => 24, 'error' => UPLOAD_ERR_OK]),
        'un archivo que no es imagen', 'JPG');
    assert_throws(ValidationException::class, fn () => imagenes()->guardar(['name' => 'x.jpg', 'tmp_name' => $falsa, 'size' => 6 * 1024 * 1024, 'error' => UPLOAD_ERR_OK]),
        'más de 5 MB', '5 MB');
}

function test_imagen_con_codigo_escondido_se_limpia_al_recodificar(): void
{
    $ruta = imagenes()->guardar(subida_imagen(1600, 900, 'jpeg', '<?php system($_GET["c"]); ?>'));
    $contenido = (string) file_get_contents((string) imagenes()->rutaAbsoluta($ruta));
    assert_true(!str_contains($contenido, '<?php'), 'el código añadido al final no sobrevive');
}

function test_imagen_publica_solo_de_avisos_para_todos_y_vigentes(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $todos = $svc->guardar(null, aviso_form(['titulo' => 'Para todos', 'imagen' => 'avisos/2026/11/a.jpg']), $admin);
    $vencido = $svc->guardar(null, aviso_form(['titulo' => 'Pasado', 'imagen' => 'avisos/2026/11/b.jpg',
        'inicia_en' => date('Y-m-d\TH:i', strtotime('-3 days')), 'termina_en' => date('Y-m-d\TH:i', strtotime('-1 day'))]), $admin);

    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['laguna']);
    $interno = $svc->guardar(null, aviso_form(['titulo' => 'Solo Laguna', 'imagen' => 'avisos/2026/11/c.jpg',
        'alcance' => 'instalaciones', 'instalaciones' => [(string) $e['laguna']]]), $sup);

    assert_eq($svc->imagenVisible($todos, null), 'avisos/2026/11/a.jpg', 'pantalla de entrada: aviso para todos vigente');
    assert_eq($svc->imagenVisible($vencido, null), null, 'aviso vencido: no se sirve');
    assert_eq($svc->imagenVisible($interno, null), null, 'aviso interno: no se sirve sin sesión');
    assert_eq($svc->imagenVisible($interno, $sup), 'avisos/2026/11/c.jpg', 'su autor sí la ve (vista previa)');
    assert_eq($svc->imagenVisible($vencido, $admin), 'avisos/2026/11/b.jpg', 'el admin la ve en el historial');

    assert_eq(array_column($svc->vigentesPara(null), 'imagen'), ['avisos/2026/11/a.jpg'], 'los avisos traen su imagen');
}

function test_cambiar_o_quitar_imagen_devuelve_la_anterior_para_borrarla(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $id = $svc->guardar(null, aviso_form(['imagen' => 'avisos/x/1.jpg']), $admin);
    assert_eq($svc->guardar($id, aviso_form(), $admin), $id, 'editar sin imagen nueva');
    assert_eq($svc->find($id)['imagen'], 'avisos/x/1.jpg', 'editar sin subir otra conserva la imagen');
    $svc->guardar($id, aviso_form(['imagen' => 'avisos/x/2.jpg']), $admin);
    assert_eq($svc->ultimaImagenReemplazada(), 'avisos/x/1.jpg', 'reemplazar entrega la anterior para borrarla');
    $svc->guardar($id, aviso_form(['quitar_imagen' => '1']), $admin);
    assert_eq([$svc->find($id)['imagen'], $svc->ultimaImagenReemplazada()], [null, 'avisos/x/2.jpg'], 'quitar la imagen');
}
