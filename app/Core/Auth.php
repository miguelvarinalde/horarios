<?php

namespace App\Core;

use App\Models\UsuarioModel;
use App\Services\PrivilegiosService;

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

    /**
     * Hay sesion Y la cuenta sigue existiendo y activa. Se revalida en cada
     * peticion (la consulta ya se hacia de todos modos para mostrar el
     * nombre y el rol): una baja o un usuario desactivado cierra tambien
     * las sesiones que ya estaban abiertas, no solo impide nuevos logins.
     */
    public static function check(): bool
    {
        if (!Session::has('usuario_id')) {
            return false;
        }

        $usuario = self::usuario();
        if ($usuario === null || empty($usuario['activo'])) {
            self::logout();
            // Sesion nueva y vacia: si la misma peticion sigue hacia /login,
            // su formulario necesita un token CSRF que se pueda guardar.
            Session::start();
            return false;
        }

        return true;
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

    /**
     * true si el usuario en sesion tiene, como minimo, todos los permisos
     * del usuario objetivo (ver PrivilegiosService). Evita que RRHH tome
     * cuentas con mas privilegios (Administrador) al resetear su clave o
     * cambiar su correo.
     */
    public static function puedeGestionarUsuario(int $usuarioObjetivoId): bool
    {
        return PrivilegiosService::puedeGestionarUsuario(self::permisos(), $usuarioObjetivoId);
    }

    /** true si el rol existe y no concede permisos que el usuario en sesion no tenga. */
    public static function puedeAsignarRol(int $rolId): bool
    {
        return PrivilegiosService::puedeAsignarRol(self::permisos(), $rolId);
    }

    public static function login(array $usuario): void
    {
        Session::regenerar();
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
