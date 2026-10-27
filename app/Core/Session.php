<?php

namespace App\Core;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            self::configurarAlmacenamiento($config);
            // Modo estricto: el servidor rechaza un id de sesion que el no
            // emitio (impide la fijacion de sesion con un id elegido por
            // un atacante). Solo cookies: nunca aceptar el id por URL.
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            // Con https la cookie lleva el prefijo __Host-: el navegador solo la acepta si es
            // Secure, de Path=/ y sin atributo Domain, asi que ningun subdominio (u otra app
            // del mismo dominio) puede sobrescribirla o fijarla.
            $nombre = $config['session']['name'];
            session_name(SecurityHeaders::esHttps() ? '__Host-' . $nombre : $nombre);
            session_set_cookie_params([
                'lifetime' => $config['session']['lifetime_minutes'] * 60,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => SecurityHeaders::esHttps(),
            ]);
            session_start();
        }
    }

    /**
     * Las sesiones se guardan en storage/sessions (fuera de public/, solo
     * legibles por la aplicacion) en vez del directorio temporal compartido del
     * hosting, y se conservan tanto como dure la sesion (el valor por defecto de
     * PHP, 24 min, las borraba mucho antes que la cookie). Si no se puede crear
     * la carpeta, se usa la configuracion por defecto de PHP.
     */
    private static function configurarAlmacenamiento(array $config): void
    {
        $minutos = max((int) $config['session']['lifetime_minutes'], (int) ($config['session']['idle_minutes'] ?? 0));
        ini_set('session.gc_maxlifetime', (string) ($minutos * 60));

        $dir = dirname(__DIR__, 2) . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // is_writable() no es fiable en todas partes (en Windows/OneDrive da false en carpetas
        // escribibles): se prueba escribiendo de verdad.
        if (is_dir($dir) && self::sePuedeEscribirEn($dir)) {
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            }
            session_save_path($dir);
        }
    }

    private static function sePuedeEscribirEn(string $dir): bool
    {
        if (is_writable($dir)) {
            return true; // caso normal (Linux): sin tocar el disco en cada peticion
        }

        $prueba = $dir . '/.prueba_' . bin2hex(random_bytes(4));
        if (@file_put_contents($prueba, '1') === false) {
            return false;
        }
        @unlink($prueba);
        return true;
    }

    /**
     * Cambia el id de sesion (y descarta el token CSRF anterior) al
     * autenticarse: un id conocido de antemano por otra persona deja de
     * servir en cuanto la victima inicia sesion.
     */
    public static function regenerar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function flash(string $key, $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, $default = null)
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string) $token);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
