<?php

namespace Tests\Unit;

use App\Core\View;
use PHPUnit\Framework\TestCase;

/** URLs versionadas de los recursos estaticos (evita servir un app.js viejo tras un despliegue). */
class ViewAssetTest extends TestCase
{
    public function test_agrega_la_fecha_de_modificacion_como_version(): void
    {
        $url = View::asset('/assets/js/app.js');

        $this->assertMatchesRegularExpression('#^/assets/js/app\.js\?v=\d{9,}$#', $url);
        $this->assertSame(
            filemtime(dirname(__DIR__, 2) . '/public/assets/js/app.js'),
            (int) substr($url, strpos($url, '?v=') + 3)
        );
    }

    public function test_un_archivo_inexistente_devuelve_la_ruta_sin_version(): void
    {
        $this->assertSame('/assets/js/no-existe.js', View::asset('/assets/js/no-existe.js'));
    }
}
