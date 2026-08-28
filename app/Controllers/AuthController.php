<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ConfiguracionSitioModel;
use App\Models\UsuarioModel;
use App\Services\Ms365AuthService;

class AuthController
{
    public function mostrarLogin(Request $request): string
    {
        if (Auth::check()) {
            Response::redirect('/');
        }

        return View::render('auth/login', [
            'error' => Session::getFlash('error'),
            'ms365Configurado' => Ms365AuthService::configurado(),
            'sitio' => ConfiguracionSitioModel::obtener(),
        ], layout: null);
    }

    public function login(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Session::flash('error', 'La sesion del formulario expiro, intenta de nuevo.');
            Response::redirect('/login');
            return;
        }

        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $usuario = UsuarioModel::findByEmail($email);

        // Se valida el bloqueo ANTES de verificar la contrasena (si ya esta
        // bloqueado no tiene sentido gastar un password_verify, y ademas
        // evita reiniciar el conteo si alguien sigue intentando).
        if ($usuario && UsuarioModel::estaBloqueado($usuario)) {
            Session::flash('error', 'Esta cuenta quedo bloqueada temporalmente por demasiados intentos fallidos. Intenta de nuevo en unos minutos.');
            Response::redirect('/login');
            return;
        }

        if (!$usuario || !$usuario['activo'] || $usuario['password_hash'] === null
            || !password_verify($password, $usuario['password_hash'])) {
            if ($usuario) {
                UsuarioModel::registrarIntentoFallido((int) $usuario['id']);
            }
            Session::flash('error', 'Correo o contrasena incorrectos.');
            Response::redirect('/login');
            return;
        }

        Auth::login($usuario);
        UsuarioModel::marcarLogin((int) $usuario['id']);

        Response::redirect($usuario['debe_cambiar_password'] ? '/cambiar-password' : '/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        Response::redirect('/login');
    }
}
