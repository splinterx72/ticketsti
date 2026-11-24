<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Alcance;
use App\Services\AvisoService;
use App\Services\ImagenAvisoService;

/**
 * Publicación de avisos (supervisores y admin TI).
 */
final class AvisoController extends Controller
{
    private const CAMPOS = ['titulo', 'mensaje', 'importancia', 'inicia_en', 'termina_en', 'alcance'];

    public function index(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->view('avisos/index', [
            'title'  => 'Avisos',
            'avisos' => (new AvisoService(Database::getInstance()))->administrables(Auth::user()),
        ]);
    }

    public function create(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->form(null, [
            'titulo' => '', 'mensaje' => '', 'importancia' => 'informativo', 'alcance' => 'todos', 'instalaciones' => [],
            'inicia_en' => date('Y-m-d\TH:i'), 'termina_en' => date('Y-m-d\TH:i', strtotime('+7 days')),
        ]);
    }

    public function store(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $this->guardar(null);
    }

    public function edit(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $aviso = $this->aviso($id);
        $aviso['inicia_en'] = date('Y-m-d\TH:i', (int) strtotime((string) $aviso['inicia_en']));
        $aviso['termina_en'] = date('Y-m-d\TH:i', (int) strtotime((string) $aviso['termina_en']));
        $this->form($id, $aviso);
    }

    public function update(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $this->aviso($id);
        $this->guardar($id);
    }

    public function finish(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $this->aviso($id);
        (new AvisoService(Database::getInstance()))->finalizar($id);
        Session::flash('success', 'Aviso retirado del inicio.');
        redirect('/supervisor/avisos');
    }

    /**
     * GET /avisos/{id}/imagen — pública solo para avisos "para todos" vigentes
     * (carrusel de la entrada); con sesión, los que le tocan o puede editar.
     */
    public function imagen(int $id): void
    {
        $user = Auth::check() ? Auth::user() : null;
        $imagenes = ImagenAvisoService::enProduccion();
        $abs = $imagenes->rutaAbsoluta((new AvisoService(Database::getInstance()))->imagenVisible($id, $user));
        if ($abs === null) {
            throw new HttpException(404, 'La imagen no existe.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($abs);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new HttpException(404, 'La imagen no existe.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($user === null ? 'public' : 'private') . ', max-age=3600');
        readfile($abs);
        exit;
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $aviso */
    private function form(?int $id, array $aviso): void
    {
        $pdo = Database::getInstance();
        $ids = (new Alcance($pdo))->instalacionesSupervisadas((int) Auth::id());
        $instalaciones = [];
        if ($ids !== []) {
            $instalaciones = $pdo->query(
                'SELECT id, nombre FROM instalaciones WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY nombre'
            )->fetchAll();
        }
        $this->view('avisos/form', [
            'title'         => $id === null ? 'Nuevo aviso' : 'Editar aviso',
            'id'            => $id,
            'aviso'         => $aviso,
            'instalaciones' => $instalaciones,
        ]);
    }

    private function guardar(?int $id): void
    {
        $in = [];
        foreach (self::CAMPOS as $c) {
            $in[$c] = $c === 'mensaje' && is_string($_POST['mensaje'] ?? null) ? (string) $_POST['mensaje'] : $this->input($c);
        }
        $in['instalaciones'] = array_map('strval', (array) ($_POST['instalaciones'] ?? []));
        $in['quitar_imagen'] = !empty($_POST['quitar_imagen']) ? '1' : '';
        $imagenes = ImagenAvisoService::enProduccion();
        $svc = new AvisoService(Database::getInstance());
        $nueva = null;
        try {
            if (ImagenAvisoService::hayArchivo($_FILES['imagen'] ?? null)) {
                $nueva = $imagenes->guardar($_FILES['imagen']);
                $in['imagen'] = $nueva;
            }
            $svc->guardar($id, $in, Auth::user());
            $imagenes->borrar($svc->ultimaImagenReemplazada());
        } catch (ValidationException $e) {
            $imagenes->borrar($nueva);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(self::CAMPOS);
            redirect($id === null ? '/supervisor/avisos/nuevo' : '/supervisor/avisos/' . $id . '/editar');
        }
        Session::flash('success', $id === null ? 'Aviso publicado.' : 'Aviso actualizado.');
        redirect('/supervisor/avisos');
    }

    /** @return array<string, mixed> */
    private function aviso(int $id): array
    {
        $svc = new AvisoService(Database::getInstance());
        $a = $svc->find($id);
        if ($a === null || !$svc->puedeEditar(Auth::user(), $a)) {
            throw new HttpException(404, 'El aviso no existe.');
        }
        return $a;
    }
}
