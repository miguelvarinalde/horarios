<?php

namespace Tests\Unit;

use App\Services\RangoFechasService;
use PHPUnit\Framework\TestCase;

/** Ventana y botones de mes de "Mis horas (registro)": mes anterior y mes actual. */
class HorasPropiasTest extends TestCase
{
    public function test_la_ventana_va_del_primer_dia_del_mes_anterior_al_ultimo_del_actual(): void
    {
        $this->assertSame(['2026-09-01', '2026-10-31'], RangoFechasService::ventanaMesAnteriorYActual(new \DateTimeImmutable('2026-10-27')));
    }

    public function test_la_ventana_en_enero_incluye_diciembre_del_anio_anterior(): void
    {
        $this->assertSame(['2026-12-01', '2027-01-31'], RangoFechasService::ventanaMesAnteriorYActual(new \DateTimeImmutable('2027-01-15')));
    }

    public function test_la_ventana_el_31_de_marzo_no_se_salta_febrero(): void
    {
        $this->assertSame(['2027-02-01', '2027-03-31'], RangoFechasService::ventanaMesAnteriorYActual(new \DateTimeImmutable('2027-03-31')));
        $this->assertSame(['2028-02-01', '2028-03-31'], RangoFechasService::ventanaMesAnteriorYActual(new \DateTimeImmutable('2028-03-31')));
    }

    public function test_botones_de_mes(): void
    {
        $m = RangoFechasService::mesesRapidos(new \DateTimeImmutable('2026-10-27'));

        $this->assertSame(['etiqueta' => 'Mes actual', 'desde' => '2026-10-01', 'hasta' => '2026-10-27'], $m[0]);
        $this->assertSame(['etiqueta' => 'Mes pasado', 'desde' => '2026-09-01', 'hasta' => '2026-09-30'], $m[1]);
    }

    public function test_los_botones_de_mes_caen_dentro_de_la_ventana(): void
    {
        $hoy = new \DateTimeImmutable('2027-01-31');
        [$min, $max] = RangoFechasService::ventanaMesAnteriorYActual($hoy);

        foreach (RangoFechasService::mesesRapidos($hoy) as $m) {
            $this->assertGreaterThanOrEqual($min, $m['desde']);
            $this->assertLessThanOrEqual($max, $m['hasta']);
            $this->assertNotNull(RangoFechasService::validar($m['desde'], $m['hasta']));
        }
    }
}
