<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\CategoriaService;

/**
 * Gestión › Categorías (solo Admin TI): nombre, descripción de lo que abarca,
 * orden y activa. Una categoría inactiva ya no se ofrece al crear tickets,
 * pero los tickets que la tienen la conservan.
 */
final class CategoriaController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $svc = new CategoriaService(Database::getInstance());
        $this->view('categorias/index', [
            'title'      => 'Categorías',
            'disponible' => $svc->disponible(),
            'categorias' => $svc->todas(),
        ]);
    }

    public function store(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->guardar(null);
    }

    public function update(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        if ((new CategoriaService(Database::getInstance()))->find($id) === null) {
            throw new HttpException(404, 'La categoría no existe.');
        }
        $this->guardar($id);
    }

    private function guardar(?int $id): void
    {
        try {
            (new CategoriaService(Database::getInstance()))->guardar(
                $id,
                $this->input('nombre'),
                $this->input('descripcion'),
                (int) $this->input('orden'),
                $id === null || $this->input('activo') === '1'
            );
            Session::flash('success', $id === null ? 'Categoría creada.' : 'Categoría actualizada.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/ti/categorias');
    }
}
