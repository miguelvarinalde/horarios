<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\UsuarioModel;

class UsuarioController
{
    public function index(Request $request): string
    {
        return View::render('usuarios/index', [
            'usuarios' => UsuarioModel::todosConRol(),
            'passwordGenerada' => Session::getFlash('password_generada'),
        ]);
    }

    public function resetearPassword(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $usuario = UsuarioModel::find($id);
        if (!$usuario) {
            Response::abort(404, 'Usuario no encontrado.');
            return;
        }

        $nueva = trim((string) $request->input('password', ''));
        $generada = false;
        if ($nueva === '') {
            $nueva = bin2hex(random_bytes(6));
            $generada = true;
        } elseif (strlen($nueva) < 8) {
            Session::flash('error', 'La contrasena debe tener al menos 8 caracteres.');
            Response::redirect('/usuarios');
            return;
        }

        // Se fuerza a que el usuario la cambie en su proximo login: ni el
        // Administrador ni RRHH deberian conocer la contrasena real del
        // usuario a largo plazo, solo la temporal para entregarsela.
        UsuarioModel::fijarPassword($id, $nueva, true);

        Session::flash('success', "Contrasena reiniciada para {$usuario['nombre']}.");
        Session::flash('password_generada', [
            'usuario' => $usuario['nombre'],
            'email' => $usuario['email'],
            'password' => $nueva,
        ]);
        Response::redirect('/usuarios');
    }
}
