<?php

namespace App\Core;

use App\Models\UsuarioModel;

/**
 * Acceso al usuario autenticado en la sesion actual. cachea en memoria
 * por request para no repetir la consulta si se llama varias veces.
 */
class Auth
{
    private static ?array $usuario = null;
    private static bool $resuelto = false;

    public static function id(): ?int
    {
        return Session::get('usuario_id');
    }

    public static function check(): bool
    {
        return Session::has('usuario_id');
    }

    public static function usuario(): ?array
    {
        if (self::$resuelto) {
            return self::$usuario;
        }
        self::$resuelto = true;

        $id = self::id();
        if ($id === null) {
            return null;
        }

        self::$usuario = UsuarioModel::withRol($id);
        return self::$usuario;
    }

    public static function permisos(): array
    {
        $id = self::id();
        if ($id === null) {
            return [];
        }
        return UsuarioModel::permisos($id);
    }

    public static function puede(string $codigoPermiso): bool
    {
        return in_array($codigoPermiso, self::permisos(), true);
    }

    public static function debeCambiarPassword(): bool
    {
        return !empty(self::usuario()['debe_cambiar_password']);
    }

    /** Si el usuario en sesion puede ver/gestionar empleados de todas las areas, no solo la propia. */
    public static function veTodasLasAreas(): bool
    {
        return self::puede('equipos.ver_todas');
    }

    public static function login(array $usuario): void
    {
        Session::put('usuario_id', $usuario['id']);
        self::$usuario = $usuario;
        self::$resuelto = true;
    }

    public static function logout(): void
    {
        self::$usuario = null;
        self::$resuelto = false;
        Session::destroy();
    }
}
