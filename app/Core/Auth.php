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
            return self::cerrarSesionInvalida(null);
        }

        // La contrasena cambio (la cambio el propio usuario o la reseteo RRHH/Administrador)
        // desde que se abrio esta sesion: cualquier sesion anterior deja de servir.
        $huella = self::huellaCredencial($usuario);
        $guardada = Session::get('_cred');
        if ($guardada === null) {
            Session::put('_cred', $huella); // sesion abierta antes de esta mejora
        } elseif (!hash_equals((string) $guardada, $huella)) {
            return self::cerrarSesionInvalida('Tu contrasena cambio: inicia sesion de nuevo.');
        }

        $ahora = time();
        $limite = self::minutosDeInactividad() * 60;
        $ultima = Session::get('_actividad');
        if ($limite > 0 && $ultima !== null && $ahora - (int) $ultima > $limite) {
            return self::cerrarSesionInvalida('Tu sesion se cerro por inactividad. Inicia sesion de nuevo.');
        }
        Session::put('_actividad', $ahora);

        return true;
    }

    /** Cierra la sesion y deja una nueva y vacia (con el aviso opcional) para que /login funcione en esta misma peticion. */
    private static function cerrarSesionInvalida(?string $aviso): bool
    {
        self::logout();
        // Sesion nueva y vacia: si la misma peticion sigue hacia /login,
        // su formulario necesita un token CSRF que se pueda guardar.
        Session::start();
        if ($aviso !== null) {
            Session::flash('error', $aviso);
        }
        return false;
    }

    private static function minutosDeInactividad(): int
    {
        static $minutos = null;
        if ($minutos === null) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $minutos = (int) ($config['session']['idle_minutes'] ?? 0);
        }
        return $minutos;
    }

    /** Huella del hash de contrasena vigente: cambia cuando cambia la contrasena. */
    private static function huellaCredencial(array $usuario): string
    {
        return hash('sha256', (string) ($usuario['password_hash'] ?? ''));
    }

    /** Tras cambiar la contrasena del usuario en sesion, esta sesion sigue valida (las demas no). */
    public static function actualizarHuellaCredencial(): void
    {
        $id = self::id();
        if ($id === null) {
            return;
        }
        $fresco = UsuarioModel::withRol((int) $id);
        if ($fresco) {
            self::$usuario = $fresco;
            Session::put('_cred', self::huellaCredencial($fresco));
        }
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
        Session::put('_cred', self::huellaCredencial($usuario));
        Session::put('_actividad', time());
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
