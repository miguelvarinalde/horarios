<?php

namespace App\Core;

/**
 * Cabeceras de seguridad aplicadas a TODA respuesta (se llama al inicio del
 * bootstrap, antes de enrutar, para que tambien cubra 404/403/errores).
 *
 * CSP sin 'unsafe-inline' en script-src: el codebase no tiene JS inline
 * (nada de onclick=/onchange= en el HTML, ver public/assets/js/app.js),
 * asi que una politica estricta no rompe nada. style-src si permite
 * 'unsafe-inline' porque las vistas usan atributos style="" ampliamente;
 * migrar eso a CSS externo queda como mejora futura, no bloquea seguridad
 * critica (el riesgo real de XSS esta en script-src, no en style-src).
 */
class SecurityHeaders
{
    public static function aplicar(): void
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);

        header("Content-Security-Policy: {$csp}");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // geolocation=(self): el modulo de marcar entrada/salida SI necesita
        // pedir ubicacion al navegador; el resto de permisos sensibles se niegan.
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(), usb=()');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
