<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\DiagnosticoService;

/** Gestión › Diagnóstico (solo Admin TI). */
final class DiagnosticoController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $svc = new DiagnosticoService(Database::getInstance(), ROOT_PATH);
        header('Cache-Control: private, no-store');
        $this->view('diagnostico/index', [
            'title'        => 'Diagnóstico',
            'migraciones'  => $svc->migraciones(),
            'extensiones'  => $svc->extensiones(),
            'carpetas'     => $svc->carpetas(),
            'errores'      => $svc->ultimosErrores(40),
            'config'       => $svc->configuracion(),
        ]);
    }
}
