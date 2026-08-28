<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Para rutas de mantenimiento del propio sistema (ej. aplicar migraciones)
 * que deben seguir siendo alcanzables por el Administrador incluso si un
 * permiso nuevo todavia no se ha sembrado en la base de datos — depende
 * del ROL (siempre existe desde la instalacion inicial), no de una fila de
 * `permisos` que podria faltar justo en el momento en que se necesita esta
 * pantalla para corregir eso mismo.
 */
class SoloAdministradorMiddleware
{
    public function __invoke(Request $request)
    {
        if (!Auth::check()) {
            Response::redirect('/login');
            return false;
        }

        if (AuthMiddleware::debeInterrumpirPorCambioDePassword($request)) {
            Response::redirect('/cambiar-password');
            return false;
        }

        if ((Auth::usuario()['rol_nombre'] ?? null) !== 'Administrador') {
            Response::abort(403, 'Solo el Administrador puede acceder a esta seccion.');
            return false;
        }

        return true;
    }
}
