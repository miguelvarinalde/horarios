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
    /** bcrypt de una contrasena inexistente: se verifica contra el cuando no hay cuenta, para igualar el tiempo de respuesta. */
    private const HASH_FICTICIO = '$2y$10$mfT/arY6gRDiGBGxW4Z54uFBVIo6SXpSW6DlnkvbUF4ENVvhlsinG';

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

        // Mismo mensaje y mismo tiempo de respuesta exista o no el correo, este
        // bloqueada o no la cuenta: asi no se puede averiguar quien tiene cuenta
        // (ni por el texto, ni porque "correo inexistente" respondia mas rapido
        // al saltarse la verificacion de contrasena).
        $mensajeGenerico = 'Correo o contrasena incorrectos, o la cuenta esta bloqueada temporalmente por intentos fallidos. Intenta de nuevo en unos minutos.';

        // Se valida el bloqueo ANTES de verificar la contrasena (si ya esta
        // bloqueado no tiene sentido gastar un password_verify real, y ademas
        // evita reiniciar el conteo si alguien sigue intentando).
        if ($usuario && UsuarioModel::estaBloqueado($usuario)) {
            password_verify($password, self::HASH_FICTICIO);
            Session::flash('error', $mensajeGenerico);
            Response::redirect('/login');
            return;
        }

        $hash = ($usuario && $usuario['activo'] && $usuario['password_hash'] !== null) ? $usuario['password_hash'] : self::HASH_FICTICIO;
        $correcta = password_verify($password, $hash);

        if (!$usuario || !$usuario['activo'] || $usuario['password_hash'] === null || !$correcta) {
            if ($usuario) {
                UsuarioModel::registrarIntentoFallido((int) $usuario['id']);
            }
            Session::flash('error', $mensajeGenerico);
            Response::redirect('/login');
            return;
        }

        Auth::login($usuario);
        UsuarioModel::marcarLogin((int) $usuario['id']);

        Response::redirect($usuario['debe_cambiar_password'] ? '/cambiar-password' : '/');
    }

    public function logout(Request $request)
    {
        // Sin token valido no se cierra la sesion: otra pagina no debe poder
        // sacar al usuario con un formulario oculto. El boton "Salir" del
        // menu ya envia el token.
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::redirect(Auth::check() ? '/' : '/login');
            return;
        }

        Auth::logout();
        Response::redirect('/login');
    }
}
