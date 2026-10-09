<?php

namespace Tests\Unit;

use App\Core\EnvFile;
use App\Core\Request;
use App\Core\SecurityHeaders;
use App\Services\Ms365AuthService;
use App\Services\RangoFechasService;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

/**
 * Piezas pequenas de la correccion de la auditoria de seguridad 2026-10-09
 * que no necesitan base de datos: rango de fechas de los informes (#7),
 * escritura segura del .env (#3), token CSRF solo por cuerpo, deteccion de
 * https detras de Cloudflare (#6) y validacion del tenant de Microsoft 365.
 */
class SeguridadNucleoTest extends TestCase
{
    // --- #7: rango de fechas ---

    public function test_rango_valido_se_normaliza(): void
    {
        $this->assertSame(['2026-10-01', '2026-10-09'], RangoFechasService::validar('2026-10-01', '2026-10-09'));
        $this->assertSame(['2026-10-09', '2026-10-09'], RangoFechasService::validar('2026-10-09', '2026-10-09'), 'Un solo dia es valido');
    }

    public function test_rango_invertido_o_mal_formado_se_rechaza(): void
    {
        $this->assertNull(RangoFechasService::validar('2026-10-09', '2026-10-01'));
        $this->assertNull(RangoFechasService::validar('abc', '2026-10-01'));
        $this->assertNull(RangoFechasService::validar('2026-10-01', null));
        $this->assertNull(RangoFechasService::validar('', ''));
        $this->assertNull(RangoFechasService::validar('2026-02-31', '2026-03-05'), 'Fecha inexistente');
        $this->assertNull(RangoFechasService::validar('2026-10-01 10:00', '2026-10-09'), 'Con hora no es Y-m-d');
        $this->assertNull(RangoFechasService::validar("2026-10-01\n", '2026-10-09'));
    }

    public function test_rango_tiene_un_tope_de_366_dias(): void
    {
        $this->assertNotNull(RangoFechasService::validar('2026-01-01', '2026-12-31'), '365 dias');
        $this->assertNotNull(RangoFechasService::validar('2024-01-01', '2024-12-31'), '366 dias (anio bisiesto)');
        $this->assertNull(RangoFechasService::validar('2024-01-01', '2025-01-01'), '367 dias');
        $this->assertNull(RangoFechasService::validar('1900-01-01', '2026-10-09'), 'Un siglo');
    }

    // --- #3: .env ---

    public function test_valores_del_env_se_leen_igual_que_se_escribieron(): void
    {
        $casos = ['simple', 'a"b\\c$d${X}e#f g', 'termina\\', 'con espacios y # y = igual', "p\$wd'comilla", 'https://horarios.inalde.edu.co', '${HOME}', '$HOME'];
        foreach ($casos as $valor) {
            $leido = Dotenv::parse('DB_PASSWORD=' . EnvFile::valor($valor) . "\nOTRA=1\n");
            $this->assertSame($valor, $leido['DB_PASSWORD'], "Ida y vuelta de: {$valor}");
            $this->assertSame(['DB_PASSWORD', 'OTRA'], array_keys($leido), 'No debe aparecer ninguna variable extra');
        }
    }

    public function test_valores_del_env_con_saltos_de_linea_se_rechazan(): void
    {
        foreach (["x\nAPP_DEBUG=true", "x\r\ny", "x\ry", "x\0y", "x\ty"] as $malo) {
            try {
                EnvFile::valor($malo);
                $this->fail('Se esperaba una excepcion para un valor con caracteres de control');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- CSRF solo por cuerpo ---

    public function test_el_token_csrf_no_se_acepta_por_la_url(): void
    {
        $soloUrl = new Request(['_csrf' => 'token-en-url'], [], ['REQUEST_METHOD' => 'POST']);
        $this->assertNull($soloUrl->input('_csrf'));

        $enCuerpo = new Request(['_csrf' => 'token-en-url'], ['_csrf' => 'token-en-cuerpo'], ['REQUEST_METHOD' => 'POST']);
        $this->assertSame('token-en-cuerpo', $enCuerpo->input('_csrf'));
    }

    public function test_otros_parametros_siguen_leyendose_de_la_url(): void
    {
        $r = new Request(['desde' => '2026-01-01'], [], ['REQUEST_METHOD' => 'GET']);
        $this->assertSame('2026-01-01', $r->input('desde'));
    }

    // --- #6: https detras de Cloudflare ---

    public function test_se_detecta_https_por_servidor_proxy_o_app_url(): void
    {
        $servidor = $_SERVER;
        $entorno = $_ENV;
        try {
            unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
            $_ENV['APP_URL'] = 'http://localhost:8000';
            $this->assertFalse(SecurityHeaders::esHttps(), 'http local');

            $_SERVER['HTTPS'] = 'on';
            $this->assertTrue(SecurityHeaders::esHttps(), 'HTTPS=on');
            $_SERVER['HTTPS'] = 'off';
            $this->assertFalse(SecurityHeaders::esHttps(), 'HTTPS=off');

            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $this->assertTrue(SecurityHeaders::esHttps(), 'proxy informa https');

            unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
            $_ENV['APP_URL'] = 'https://horarios.inalde.edu.co';
            $this->assertTrue(SecurityHeaders::esHttps(), 'APP_URL https (Cloudflare sin cabecera)');
        } finally {
            $_SERVER = $servidor;
            $_ENV = $entorno;
        }
    }

    // --- tenant de Microsoft 365 ---

    public function test_tenant_acepta_guid_y_dominio_pero_no_alias_multitenant(): void
    {
        $this->assertTrue(Ms365AuthService::tenantValido('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertTrue(Ms365AuthService::tenantValido('inalde.edu.co'));
        $this->assertTrue(Ms365AuthService::tenantValido('contoso.onmicrosoft.com'));

        foreach (['common', 'organizations', 'consumers', '', 'a/b', 'inalde.edu.co/../x', 'x y.com', "inalde.edu.co\n", '3f2504e0-4f89-11d3-9a0c'] as $malo) {
            $this->assertFalse(Ms365AuthService::tenantValido($malo), "No deberia aceptar: " . json_encode($malo));
        }
    }
}
