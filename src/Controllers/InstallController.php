<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use PDOException;

/**
 * Asistente de primer uso: crea la cuenta admin_ti inicial.
 *
 * Solo funciona si 'app.install_enabled' es true Y aún no existe ningún
 * admin_ti. Sustituye a la creación por SSH/CLI que no hay en hosting compartido.
 */
final class InstallController extends Controller
{
    public function show(): void
    {
        $this->guard();
        $this->view('install/index', ['title' => 'Instalación', 'authUser' => null], 200, 'layouts/guest');
    }

    public function store(): void
    {
        $this->guard();
        $this->verifyCsrf();

        $ficha = $this->input('ficha');
        $nombre = $this->input('nombre');
        $email = mb_strtolower($this->input('email'));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (preg_match('/^[A-Za-z0-9-]{1,20}$/', $ficha) !== 1) {
            $errors[] = 'La ficha es obligatoria (máx. 20 caracteres alfanuméricos).';
        }
        if ($nombre === '' || mb_strlen($nombre) > 150) {
            $errors[] = 'El nombre es obligatorio (máx. 150 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = 'Captura un correo electrónico válido.';
        }
        if (mb_strlen($password) < 10) {
            $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'Las contraseñas no coinciden.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['ficha', 'nombre', 'email']);
            redirect('/instalar');
        }

        $pdo = Database::getInstance();
        try {
            // Si la ficha ya vino en el GECA se promueve; si no, se crea.
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (ficha, nombre, email, password_hash, rol, registrado, activo, email_verificado_en, admin_local_cambiado_en)
                 VALUES (?, ?, ?, ?, 'admin_ti', 1, 1, NOW(), CURDATE())
                 ON DUPLICATE KEY UPDATE
                     id = LAST_INSERT_ID(id),
                     email = VALUES(email),
                     password_hash = VALUES(password_hash),
                     rol = 'admin_ti',
                     registrado = 1,
                     activo = 1,
                     email_verificado_en = NOW(),
                     admin_local_cambiado_en = COALESCE(admin_local_cambiado_en, CURDATE())"
            );
            $stmt->execute([$ficha, $nombre, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[Install] ' . $e->getMessage());
            Session::flash('danger', 'No se pudo crear la cuenta. Verifica que el correo no esté en uso por otra ficha.');
            $this->rememberInput(['ficha', 'nombre', 'email']);
            redirect('/instalar');
        }

        Auth::login($userId);
        Session::flash('success', 'Cuenta de administrador creada. Recuerda poner install_enabled en false en config/config.php.');
        redirect('/');
    }

    private function guard(): void
    {
        if (config('app.install_enabled', false) !== true) {
            throw new HttpException(404, 'La página solicitada no existe.');
        }
        $count = (int) Database::getInstance()
            ->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin_ti' AND registrado = 1")
            ->fetchColumn();
        if ($count > 0) {
            throw new HttpException(404, 'La instalación ya fue completada.');
        }
    }
}
