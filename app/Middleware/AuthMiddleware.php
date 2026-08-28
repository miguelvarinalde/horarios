<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    public function __invoke(Request $request)
    {
        if (!Auth::check()) {
            Response::redirect('/login');
            return false;
        }

        if (self::debeInterrumpirPorCambioDePassword($request)) {
            Response::redirect('/cambiar-password');
            return false;
        }

        return true;
    }

    /**
     * Si el usuario tiene pendiente un cambio de contrasena obligatorio
     * (recien creado o recien reseteado), se le redirige ahi antes que a
     * cualquier otra pantalla — salvo la propia pantalla de cambio y logout.
     */
    public static function debeInterrumpirPorCambioDePassword(Request $request): bool
    {
        $rutasPermitidas = ['/cambiar-password', '/logout'];
        return Auth::debeCambiarPassword() && !in_array($request->path(), $rutasPermitidas, true);
    }
}
