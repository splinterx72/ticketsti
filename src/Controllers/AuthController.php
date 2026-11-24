<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;

/**
 * Inicio y cierre de sesión (ficha o correo + contraseña).
 * El auto-registro por ficha se implementa en la Fase 3.
 */
final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        $avisos = [];
        try {
            $avisos = (new \App\Services\AvisoService(\App\Config\Database::getInstance()))->vigentesPara(null);
        } catch (\Throwable $e) {
            error_log('[Avisos] ' . $e->getMessage());
        }
        $this->view('auth/login', [
            'title'      => 'Iniciar sesión',
            'authUser'   => null,
            'avisos'     => $avisos,
            'mesaCorreo' => (string) config('mail.mesa_institucional', ''),
        ], 200, 'layouts/login');
    }

    public function login(): void
    {
        $this->verifyCsrf();

        $maxAttempts = (int) config('security.login_max_attempts', 5);
        $lockSeconds = (int) config('security.login_lock_seconds', 300);

        $lockedUntil = (int) Session::get('_login_locked_until', 0);
        if ($lockedUntil > time()) {
            $minutes = (int) ceil(($lockedUntil - time()) / 60);
            Session::flash('danger', "Demasiados intentos fallidos. Intenta de nuevo en {$minutes} minuto(s).");
            redirect('/login');
        }

        $identifier = $this->input('identificador');
        $password = (string) ($_POST['password'] ?? '');

        $result = Auth::attempt($identifier, $password);
        if ($result === null) {
            $attempts = (int) Session::get('_login_attempts', 0) + 1;
            Session::set('_login_attempts', $attempts);
            if ($attempts >= $maxAttempts) {
                Session::set('_login_locked_until', time() + $lockSeconds);
                Session::set('_login_attempts', 0);
            }
            $this->rememberInput(['identificador']);
            Session::flash('danger', 'Ficha/correo o contraseña incorrectos, o la cuenta aún no está activada.');
            redirect('/login');
        }

        Session::remove('_login_attempts');
        Session::remove('_login_locked_until');
        Auth::login($result['id']);
        Session::flash('success', 'Bienvenido.');
        redirect('/');
    }

    public function logout(): void
    {
        $this->verifyCsrf();
        Auth::logout();
        Session::start((array) config('session', []), STORAGE_PATH . '/sessions');
        Session::flash('info', 'Sesión cerrada correctamente.');
        redirect('/login');
    }
}
