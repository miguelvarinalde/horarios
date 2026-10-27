<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Exige que el usuario autenticado tenga un permiso especifico.
 * Uso en rutas: [RbacMiddleware::class, 'novedades.aprobar']
 * o con varios permisos alternativos: [RbacMiddleware::class, 'reportes.ver|reportes.horas_propias']
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

        // "a|b" = basta con tener cualquiera de los permisos listados.
        $tiene = false;
        foreach (explode('|', $this->permiso) as $codigo) {
            if (Auth::puede($codigo)) {
                $tiene = true;
                break;
            }
        }

        if (!$tiene) {
            Response::abort(403, 'No tienes permiso para realizar esta accion.');
            return false;
        }

        return true;
    }
}
