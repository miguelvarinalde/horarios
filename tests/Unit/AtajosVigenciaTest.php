<?php

namespace Tests\Unit;

use App\Services\RangoFechasService;
use PHPUnit\Framework\TestCase;

/**
 * Rangos rapidos del formulario "Nueva vigencia de horario": semana actual,
 * semana siguiente, quincena (2 semanas) y mes calendario.
 */
class AtajosVigenciaTest extends TestCase
{
    /** @return array<string, array{desde: string, hasta: string}> */
    private function atajos(string $hoy): array
    {
        $porClave = [];
        foreach (RangoFechasService::atajosDeVigencia(new \DateTimeImmutable($hoy)) as $a) {
            $porClave[$a['clave']] = ['desde' => $a['desde'], 'hasta' => $a['hasta']];
        }
        return $porClave;
    }

    public function test_un_viernes(): void
    {
        $a = $this->atajos('2026-10-09');

        $this->assertSame(['desde' => '2026-10-05', 'hasta' => '2026-10-11'], $a['semana']);
        $this->assertSame(['desde' => '2026-10-12', 'hasta' => '2026-10-18'], $a['semana_siguiente']);
        $this->assertSame(['desde' => '2026-10-05', 'hasta' => '2026-10-18'], $a['quincena']);
        $this->assertSame(['desde' => '2026-10-01', 'hasta' => '2026-10-31'], $a['mes']);
        $this->assertSame(['desde' => '2026-10-12', 'hasta' => '2026-10-25'], $a['quincena_siguiente']);
        $this->assertSame(['desde' => '2026-11-01', 'hasta' => '2026-11-30'], $a['mes_siguiente']);
    }

    public function test_un_lunes_y_un_domingo_pertenecen_a_la_misma_semana(): void
    {
        $this->assertSame('2026-10-05', $this->atajos('2026-10-05')['semana']['desde']);
        $this->assertSame(['desde' => '2026-10-05', 'hasta' => '2026-10-11'], $this->atajos('2026-10-11')['semana']);
        $this->assertSame('2026-10-12', $this->atajos('2026-10-11')['semana_siguiente']['desde']);
    }

    public function test_la_semana_cruza_de_mes_y_de_anio(): void
    {
        $a = $this->atajos('2026-12-31'); // jueves

        $this->assertSame(['desde' => '2026-12-28', 'hasta' => '2027-01-03'], $a['semana']);
        $this->assertSame(['desde' => '2027-01-04', 'hasta' => '2027-01-10'], $a['semana_siguiente']);
        $this->assertSame(['desde' => '2026-12-01', 'hasta' => '2026-12-31'], $a['mes']);
    }

    public function test_el_mes_respeta_anios_bisiestos(): void
    {
        $this->assertSame('2028-02-29', $this->atajos('2028-02-10')['mes']['hasta']);
        $this->assertSame('2027-02-28', $this->atajos('2027-02-10')['mes']['hasta']);
    }

    public function test_el_mes_siguiente_no_se_salta_meses_cortos_ni_el_cambio_de_anio(): void
    {
        $this->assertSame(['desde' => '2027-02-01', 'hasta' => '2027-02-28'], $this->atajos('2027-01-31')['mes_siguiente'], '31 de enero -> febrero, no marzo');
        $this->assertSame(['desde' => '2028-02-01', 'hasta' => '2028-02-29'], $this->atajos('2028-01-30')['mes_siguiente']);
        $this->assertSame(['desde' => '2027-01-01', 'hasta' => '2027-01-31'], $this->atajos('2026-12-15')['mes_siguiente']);
    }

    public function test_la_quincena_siguiente_empieza_el_proximo_lunes_y_dura_14_dias(): void
    {
        $a = $this->atajos('2026-12-31'); // jueves

        $this->assertSame(['desde' => '2027-01-04', 'hasta' => '2027-01-17'], $a['quincena_siguiente']);
        $this->assertSame($a['semana_siguiente']['desde'], $a['quincena_siguiente']['desde']);
        $dias = (new \DateTimeImmutable($a['quincena_siguiente']['desde']))->diff(new \DateTimeImmutable($a['quincena_siguiente']['hasta']))->days + 1;
        $this->assertSame(14, $dias);
    }

    public function test_la_quincena_dura_exactamente_14_dias(): void
    {
        $q = $this->atajos('2026-03-18')['quincena'];
        $dias = (new \DateTimeImmutable($q['desde']))->diff(new \DateTimeImmutable($q['hasta']))->days + 1;

        $this->assertSame(14, $dias);
        $this->assertNotNull(RangoFechasService::validar($q['desde'], $q['hasta']));
    }
}
