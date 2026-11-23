<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;
use App\Services\AdminLocalService;

/**
 * Controlador base con utilidades de render, CSRF y control de acceso.
 */
abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/main'): void
    {
        if (!array_key_exists('authUser', $data)) {
            $data['authUser'] = Auth::user();
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo View::render($template, $data, $layout);
    }

    protected function verifyCsrf(): void
    {
        $token = $_POST[Csrf::FIELD] ?? null;
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            throw new HttpException(403, 'La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.');
        }
    }

    protected function requireLogin(): void
    {
        if (!Auth::check()) {
            Session::flash('warning', 'Inicia sesión para continuar.');
            redirect('/login');
        }
        // Correo de la empresa sin verificar (spec 7.6 bis): solo puede verificarlo.
        if (Auth::requiereVerificacion()) {
            redirect('/verificar-correo');
        }
        // Contraseña de admin local a 5 días o menos: bloqueo total (spec 7.5).
        if (AdminLocalService::bloqueado(Auth::user())) {
            redirect('/admin-local');
        }
    }

    /** @param list<string> $roles */
    protected function requireRole(array $roles): void
    {
        $this->requireLogin();
        if (!Auth::hasRole($roles)) {
            throw new HttpException(403, 'No tienes permisos para acceder a esta sección.');
        }
    }

    protected function input(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Guarda los valores del formulario para repoblarlo tras un redirect.
     *
     * @param list<string> $keys
     */
    protected function rememberInput(array $keys): void
    {
        $old = [];
        foreach ($keys as $k) {
            $old[$k] = $this->input($k);
        }
        Session::set('_old', $old);
    }
}
