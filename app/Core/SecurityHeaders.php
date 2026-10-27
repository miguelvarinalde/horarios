<?php

namespace App\Core;

/**
 * Cabeceras de seguridad aplicadas a TODA respuesta (se llama al inicio del
 * bootstrap, antes de enrutar, para que tambien cubra 404/403/errores).
 *
 * CSP sin 'unsafe-inline' en script-src NI en style-src/style-src-elem: el
 * codebase no tiene JS ni bloques <style> inline (ver public/assets/js/app.js
 * y public/assets/css/). Las vistas si usan atributos style="" (unas 100),
 * que se permiten solo con style-src-attr: asi los estilos de ELEMENTO
 * quedan bloqueados (no se puede inyectar una hoja de estilos) y solo se
 * conserva el atributo, de bajo riesgo. Migrar esos atributos a clases y
 * quitar style-src-attr queda como mejora futura.
 *
 * La misma lista de cabeceras se repite en public/.htaccess (Permissions-
 * Policy, Referrer-Policy, X-Content-Type-Options): el hosting agrega las
 * suyas y, al llegar directo al origen, quedaban DUPLICADAS y
 * contradictorias (geolocation=() bloqueaba el marcado con ubicacion).
 * SeguridadNucleoTest verifica que ambas copias coincidan.
 */
class SecurityHeaders
{
    public const PERMISSIONS_POLICY = 'geolocation=(self), camera=(), microphone=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), serial=(), hid=(), bluetooth=(), display-capture=()';

    /** @return array<string, string> nombre => valor, listo para header() */
    public static function cabeceras(bool $https): array
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            "script-src-attr 'none'",
            "style-src 'self'",
            "style-src-elem 'self'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-src 'none'",
            "worker-src 'none'",
            "manifest-src 'self'",
            "media-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];
        if ($https) {
            $csp[] = 'upgrade-insecure-requests';
        }

        $cabeceras = [
            'Content-Security-Policy' => implode('; ', $csp),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // geolocation=(self): el modulo de marcar entrada/salida SI necesita
            // pedir ubicacion al navegador; el resto de permisos sensibles se niegan.
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            // Aislamiento entre origenes: ninguna otra pagina puede abrir esta ventana
            // ni embeber sus recursos (el login con Microsoft es una redireccion completa, no un popup).
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if ($https) {
            $cabeceras['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $cabeceras;
    }

    public static function aplicar(): void
    {
        foreach (self::cabeceras(self::esHttps()) as $nombre => $valor) {
            header("{$nombre}: {$valor}");
        }

        // No anunciar la tecnologia ni la version de PHP.
        header_remove('X-Powered-By');
    }

    /**
     * Detras de Cloudflare/un proxy, $_SERVER['HTTPS'] puede llegar vacio
     * aunque el visitante use https; en ese caso la cookie de sesion
     * quedaba sin el atributo Secure y sin HSTS. Se considera https si el
     * servidor lo dice, si el proxy lo informa, o si APP_URL es https.
     */
    public static function esHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return str_starts_with(strtolower((string) ($_ENV['APP_URL'] ?? '')), 'https://');
    }
}
