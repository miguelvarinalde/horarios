<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Exige que el usuario autenticado tenga un permiso especifico.
 * Uso en rutas: [RbacMiddleware::class, 'novedades.aprobar']
 */
class RbacMiddleware
{
    public function __construct(private string $permiso)
    {
    }

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

        if (!Auth::puede($this->permiso)) {
            Response::abort(403, 'No tienes permiso para realizar esta accion.');
            return false;
        }

        return true;
    }
}
