<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\FormatoService;

/**
 * Gestión › Formatos (solo Admin TI): logos y textos de cada formato por año.
 * Editar una versión que ya tiene documentos crea otra; lo firmado no cambia.
 */
final class FormatoAdminController extends Controller
{
    private const CAMPOS = [
        'titulo', 'encabezado', 'lugar_omision', 'organismo_omision', 'seccion_usuario', 'nota_resguardo', 'intro',
        'parrafos', 'subtitulo', 'parrafos2', 'manifiesto', 'titulo_motivos', 'motivos', 'notas', 'metodos',
        'firma_usuario', 'firma_responsable', 'firma_ti',
    ];

    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $svc = new FormatoService(Database::getInstance());
        $vigentes = [];
        foreach (array_keys(FormatoService::DEFAULT) as $clave) {
            $vigentes[$clave] = $svc->plantillaVigente($clave);
        }
        $this->view('formatos/admin', [
            'title'    => 'Formatos',
            'versiones' => $svc->listado(),
            'vigentes' => $vigentes,
        ]);
    }

    public function edit(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $svc = new FormatoService(Database::getInstance());
        try {
            $p = $svc->plantilla($id);
        } catch (ValidationException) {
            throw new HttpException(404, 'El formato no existe.');
        }
        $usados = (int) (array_values(array_filter($svc->listado(), static fn (array $v): bool => (int) $v['id'] === $id))[0]['usados'] ?? 0);
        $this->view('formatos/editar', ['title' => 'Editar formato', 'p' => $p, 'usados' => $usados, 'muestra' => self::muestra($p)]);
    }

    public function update(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $svc = new FormatoService(Database::getInstance());
        $guardados = [];
        try {
            $svc->plantilla($id);
            foreach (['izq', 'der', 'pie'] as $pos) {
                $subidas = self::subidas('logo_' . $pos);
                if ($subidas !== []) {
                    $guardados[$pos] = $svc->guardarLogo($subidas[0]);
                }
            }
            $in = [];
            foreach (self::CAMPOS as $c) {
                $in[$c] = (string) ($_POST[$c] ?? '');
            }
            $nuevo = $svc->guardarPlantilla($id, $in, $guardados, Auth::user());
            Session::flash('success', $nuevo === $id
                ? 'Formato guardado.'
                : 'Esta versión ya tenía documentos firmados: se creó una versión nueva para los próximos formatos; los anteriores no cambian.');
            redirect('/ti/formatos/' . $nuevo . '/editar');
        } catch (ValidationException $e) {
            foreach ($guardados as $ruta) {
                @unlink(STORAGE_PATH . '/' . $ruta);
            }
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/ti/formatos/' . $id . '/editar');
        }
    }

    /** POST /ti/formatos/anio: prepara la versión del año (copia la más reciente). */
    public function nuevoAnio(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        try {
            $id = (new FormatoService(Database::getInstance()))->nuevoAnio($this->input('clave'), (int) $this->input('anio'), Auth::user());
            Session::flash('success', 'Versión creada con los textos y logos actuales; cambia lo que corresponda a ese año.');
            redirect('/ti/formatos/' . $id . '/editar');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/ti/formatos');
        }
    }

    /**
     * Documento de ejemplo para la vista previa (datos ficticios, sin firmas).
     *
     * @param array<string, mixed> $p
     * @return array{f: array<string, mixed>, doc: array<string, mixed>}
     */
    private static function muestra(array $p): array
    {
        $logo = static function (?string $ruta): ?string {
            $path = $ruta ? STORAGE_PATH . '/' . $ruta : null;
            if ($path === null || str_contains($path, '..') || !is_file($path)) {
                return null;
            }
            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
        };
        return [
            'f' => [
                'titulo' => $p['titulo'], 'contenido' => $p['contenido'], 'motivos_idx' => [1], 'metodo' => null,
                'folio' => 'FF-' . $p['anio'] . '-000000', 'ticket_folio' => 'TK-' . $p['anio'] . '-000000', 'codigo' => 'EJEMPLO',
            ],
            'doc' => [
                'd' => [
                    'usuario' => 'NOMBRE DEL TRABAJADOR', 'ficha' => '000000', 'ext' => '00000',
                    'organismo' => $p['contenido']['organismo_omision'] ?? '', 'subdireccion' => 'SUBDIRECCIÓN (del GECA)', 'gerencia' => 'GERENCIA (del GECA)',
                    'ubicacion' => 'Instalación › Edificio › Nivel › Ala · referencia', 'remedy' => '',
                    'tipo' => 'LAPTOP', 'marca' => 'MARCA', 'modelo' => 'MODELO', 'serie' => 'SERIE', 'inventario' => 'N/A',
                    'lugar' => $p['contenido']['lugar_omision'] ?? '', 'fecha' => date('Y-m-d'),
                ],
                'firmas' => [],
                'logos' => ['izq' => $logo($p['logo_izq']), 'der' => $logo($p['logo_der']), 'pie' => $logo($p['logo_pie'])],
            ],
        ];
    }
}
