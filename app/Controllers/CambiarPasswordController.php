<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\UsuarioModel;

/**
 * Cambio de contrasena del propio usuario autenticado: se usa tanto de
 * forma obligatoria (justo despues de que un Administrador/RRHH resetea la
 * clave, o al primer login de un usuario recien creado) como voluntaria
 * (desde el enlace "Cambiar mi contrasena" en la barra superior).
 */
class CambiarPasswordController
{
    public function mostrar(Request $request): string
    {
        $obligatorio = !empty(Auth::usuario()['debe_cambiar_password']);

        // Si es obligatorio (recien reseteada o primer login), se muestra
        // como pantalla independiente, sin el menu normal, para que el
        // usuario no pueda navegar a otra parte sin cambiarla primero
        // (el AuthMiddleware tambien lo fuerza a nivel de rutas).
        return View::render('auth/cambiar_password', [
            'error' => Session::getFlash('error'),
            'obligatorio' => $obligatorio,
        ], layout: $obligatorio ? null : 'layouts/app');
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Session::flash('error', 'La sesion del formulario expiro, intenta de nuevo.');
            Response::redirect('/cambiar-password');
            return;
        }

        $usuario = Auth::usuario();
        $actual = (string) $request->input('password_actual', '');
        $nueva = (string) $request->input('password_nueva', '');
        $confirmacion = (string) $request->input('password_confirmacion', '');

        if ($usuario['password_hash'] === null || !password_verify($actual, $usuario['password_hash'])) {
            Session::flash('error', 'La contrasena actual no es correcta.');
            Response::redirect('/cambiar-password');
            return;
        }

        if (strlen($nueva) < 8) {
            Session::flash('error', 'La nueva contrasena debe tener al menos 8 caracteres.');
            Response::redirect('/cambiar-password');
            return;
        }

        if ($nueva !== $confirmacion) {
            Session::flash('error', 'La confirmacion no coincide con la nueva contrasena.');
            Response::redirect('/cambiar-password');
            return;
        }

        UsuarioModel::fijarPassword((int) $usuario['id'], $nueva, false);

        Session::flash('success', 'Contrasena actualizada correctamente.');
        Response::redirect('/');
    }
}
