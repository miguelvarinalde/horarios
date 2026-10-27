<?php

namespace Tests\Unit;

use App\Core\SecurityHeaders;
use App\Services\PoliticaPasswordService;
use PHPUnit\Framework\TestCase;

/**
 * Endurecimiento 2026-10-27: cabeceras de seguridad, politica de contrasenas
 * y coherencia entre SecurityHeaders y public/.htaccess.
 */
class EndurecimientoTest extends TestCase
{
    /** @return array<string, string> directiva => valor */
    private function csp(bool $https = true): array
    {
        $directivas = [];
        foreach (explode('; ', SecurityHeaders::cabeceras($https)['Content-Security-Policy']) as $d) {
            [$nombre, $valor] = array_pad(explode(' ', $d, 2), 2, '');
            $directivas[$nombre] = $valor;
        }
        return $directivas;
    }

    private function htaccess(): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
    }

    public function test_la_csp_no_permite_scripts_ni_hojas_de_estilo_inline(): void
    {
        $csp = $this->csp();

        $this->assertSame("'self'", $csp['script-src']);
        $this->assertSame("'none'", $csp['script-src-attr']);
        $this->assertSame("'self'", $csp['style-src']);
        $this->assertSame("'self'", $csp['style-src-elem']);
        $this->assertStringNotContainsString('unsafe-eval', implode(' ', $csp));
        // Unico uso de unsafe-inline: atributos style="" (style-src-attr).
        $conInline = array_keys(array_filter($csp, fn ($v) => str_contains($v, 'unsafe-inline')));
        $this->assertSame(['style-src-attr'], $conInline);
    }

    public function test_la_csp_cierra_framing_formularios_y_objetos(): void
    {
        $csp = $this->csp();

        $this->assertSame("'none'", $csp['frame-ancestors']);
        $this->assertSame("'none'", $csp['frame-src']);
        $this->assertSame("'none'", $csp['object-src']);
        $this->assertSame("'self'", $csp['base-uri']);
        $this->assertSame("'self'", $csp['form-action']);
        $this->assertSame("'self'", $csp['default-src']);
    }

    public function test_upgrade_insecure_requests_solo_con_https(): void
    {
        $this->assertArrayHasKey('upgrade-insecure-requests', $this->csp(true));
        $this->assertArrayNotHasKey('upgrade-insecure-requests', $this->csp(false));
    }

    public function test_cabeceras_obligatorias(): void
    {
        $c = SecurityHeaders::cabeceras(true);

        $this->assertSame('nosniff', $c['X-Content-Type-Options']);
        $this->assertSame('DENY', $c['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $c['Referrer-Policy']);
        $this->assertSame('same-origin', $c['Cross-Origin-Opener-Policy']);
        $this->assertSame('same-origin', $c['Cross-Origin-Resource-Policy']);
        $this->assertSame('none', $c['X-Permitted-Cross-Domain-Policies']);
        $this->assertStringContainsString('includeSubDomains', $c['Strict-Transport-Security']);
        $this->assertStringContainsString('preload', $c['Strict-Transport-Security']);
        preg_match('/max-age=(\d+)/', $c['Strict-Transport-Security'], $m);
        $this->assertGreaterThanOrEqual(31536000, (int) ($m[1] ?? 0));
    }

    public function test_hsts_no_se_envia_por_http(): void
    {
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::cabeceras(false));
    }

    public function test_la_geolocalizacion_sigue_permitida_para_el_propio_sitio(): void
    {
        $politica = SecurityHeaders::cabeceras(true)['Permissions-Policy'];

        $this->assertStringContainsString('geolocation=(self)', $politica);
        $this->assertStringNotContainsString('geolocation=()', $politica);
        foreach (['camera=()', 'microphone=()', 'payment=()', 'usb=()'] as $negado) {
            $this->assertStringContainsString($negado, $politica);
        }
    }

    public function test_htaccess_repite_exactamente_las_cabeceras_de_la_aplicacion(): void
    {
        $htaccess = $this->htaccess();
        $c = SecurityHeaders::cabeceras(true);

        foreach (['Permissions-Policy', 'Referrer-Policy', 'X-Content-Type-Options'] as $nombre) {
            $this->assertStringContainsString(
                "Header always set {$nombre} \"{$c[$nombre]}\"",
                $htaccess,
                "public/.htaccess debe fijar {$nombre} con el mismo valor que SecurityHeaders"
            );
            $this->assertStringContainsString("Header always unset {$nombre}", $htaccess);
        }
    }

    public function test_htaccess_no_bloquea_index_php_ni_well_known(): void
    {
        $htaccess = $this->htaccess();

        preg_match_all('/<FilesMatch "([^"]+)">\s*Require all denied/', $htaccess, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $patron) {
            foreach (['index.php', 'security.txt', 'app.js', 'app.css'] as $archivo) {
                $this->assertSame(0, preg_match('/' . $patron . '/', $archivo), "El patron {$patron} bloquearia {$archivo}");
            }
        }
        $this->assertStringContainsString('(?!well-known)', $htaccess);
    }

    public function test_la_redireccion_a_https_no_puede_causar_un_bucle_detras_de_cloudflare(): void
    {
        $htaccess = $this->htaccess();

        $this->assertStringContainsString('RewriteCond %{HTTP:CF-Visitor} !"scheme":"https"', $htaccess);
        $this->assertStringContainsString('RewriteCond %{HTTP:X-Forwarded-Proto} !https', $htaccess);
        $this->assertStringContainsString('RewriteCond %{HTTP_HOST} \.inalde\.edu\.co$', $htaccess);
    }

    public function test_politica_de_contrasenas_acepta_una_frase_larga(): void
    {
        $this->assertNull(PoliticaPasswordService::validar('cafe-con-leche-a-las-siete', 'ana.perez@inalde.edu.co'));
        $this->assertNull(PoliticaPasswordService::validar('Zorro_Azul_Corre_88', null));
    }

    public function test_politica_de_contrasenas_rechaza_las_debiles(): void
    {
        $malas = [
            'corta1A!' => 'menos de 10',
            '1234567890' => 'secuencia numerica',
            'qwertyuiop' => 'teclado',
            'aaaaaaaaaaaa' => 'repetitiva',
            'Password2026!' => 'palabra comun con numeros',
            'Inalde2026!!' => 'palabra comun institucional',
            'C0ntrasena123' => 'palabra comun con leet',
            'abcdefghijklmn' => 'alfabeto',
        ];
        foreach ($malas as $clave => $motivo) {
            $this->assertNotNull(PoliticaPasswordService::validar($clave), "Debia rechazar ({$motivo}): {$clave}");
        }
    }

    public function test_politica_de_contrasenas_rechaza_el_correo_la_actual_y_las_muy_largas(): void
    {
        $this->assertNotNull(PoliticaPasswordService::validar('juan.garcia-2027-x', 'juan.garcia@inalde.edu.co'), 'contiene la parte local del correo');
        $this->assertNotNull(PoliticaPasswordService::validar(str_repeat('ab1', 30)), 'mas de 72 bytes (bcrypt la truncaria)');

        $hash = password_hash('mi-frase-secreta-larga', PASSWORD_DEFAULT);
        $this->assertNotNull(PoliticaPasswordService::validar('mi-frase-secreta-larga', null, $hash), 'igual a la actual');
        $this->assertNull(PoliticaPasswordService::validar('otra-frase-secreta-larga', null, $hash));
    }
}
