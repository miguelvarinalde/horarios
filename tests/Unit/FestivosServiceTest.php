<?php

namespace Tests\Unit;

use App\Services\FestivosService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Verifica el generador de festivos por propiedades estructurales (siempre
 * verdaderas sin importar el año), en lugar de fechas exactas memorizadas a
 * mano, que serian faciles de transcribir mal. Colombia tiene 18 festivos
 * civiles al año: 6 fijos, 7 trasladables al lunes por fecha base + 3
 * trasladables al lunes calculados desde la Pascua, mas Jueves y Viernes
 * Santo (no se trasladan).
 *
 * Antes de poner el sistema en produccion, el administrador debe comparar
 * el calendario generado para el año en curso contra una fuente oficial
 * (ver plan: assumption abierta).
 */
class FestivosServiceTest extends TestCase
{
    private FestivosService $service;

    protected function setUp(): void
    {
        $this->service = new FestivosService();
    }

    public function test_genera_18_festivos_sin_fechas_duplicadas(): void
    {
        $festivos = $this->service->calcularAnio(2026);

        $this->assertCount(18, $festivos);

        $fechas = array_column($festivos, 'fecha');
        $this->assertCount(18, array_unique($fechas), 'No deberian existir dos festivos en la misma fecha');
    }

    public function test_festivos_fijos_no_se_trasladan(): void
    {
        $festivos = $this->indexarPorNombre($this->service->calcularAnio(2026));

        $this->assertSame('2026-01-01', $festivos['Año Nuevo']);
        $this->assertSame('2026-05-01', $festivos['Dia del Trabajo']);
        $this->assertSame('2026-07-20', $festivos['Independencia de Colombia']);
        $this->assertSame('2026-08-07', $festivos['Batalla de Boyaca']);
        $this->assertSame('2026-12-08', $festivos['Inmaculada Concepcion']);
        $this->assertSame('2026-12-25', $festivos['Navidad']);
    }

    public function test_festivos_ley_emiliani_siempre_caen_en_lunes(): void
    {
        foreach ([2025, 2026, 2027, 2028] as $anio) {
            foreach ($this->service->calcularAnio($anio) as $festivo) {
                if ($festivo['tipo'] !== 'ley_emiliani') {
                    continue;
                }
                $diaSemana = (int) (new DateTimeImmutable($festivo['fecha']))->format('N');
                $this->assertSame(1, $diaSemana, "{$festivo['nombre']} ({$festivo['fecha']}) del año {$anio} deberia caer en lunes");
            }
        }
    }

    public function test_jueves_y_viernes_santo_son_consistentes_con_el_domingo_de_pascua(): void
    {
        foreach ([2025, 2026, 2027, 2028] as $anio) {
            $festivos = $this->indexarPorNombre($this->service->calcularAnio($anio));

            $juevesSanto = new DateTimeImmutable($festivos['Jueves Santo']);
            $viernesSanto = new DateTimeImmutable($festivos['Viernes Santo']);

            $this->assertSame(4, (int) $juevesSanto->format('N'), "Jueves Santo {$anio} deberia caer un jueves");
            $this->assertSame(5, (int) $viernesSanto->format('N'), "Viernes Santo {$anio} deberia caer un viernes");
            $this->assertSame(1, $juevesSanto->diff($viernesSanto)->days, 'Viernes Santo es exactamente un dia despues de Jueves Santo');

            $domingoPascua = $viernesSanto->modify('+2 days');
            $this->assertSame(0, (int) $domingoPascua->format('w'), 'Dos dias despues de Viernes Santo debe ser domingo (Pascua)');
        }
    }

    public function test_festivos_movibles_basados_en_pascua_quedan_en_orden_y_son_lunes(): void
    {
        foreach ([2025, 2026, 2027, 2028] as $anio) {
            $festivos = $this->indexarPorNombre($this->service->calcularAnio($anio));

            $ascension = new DateTimeImmutable($festivos['Ascension del Señor']);
            $corpusChristi = new DateTimeImmutable($festivos['Corpus Christi']);
            $sagradoCorazon = new DateTimeImmutable($festivos['Sagrado Corazon de Jesus']);

            foreach ([$ascension, $corpusChristi, $sagradoCorazon] as $fecha) {
                $this->assertSame(1, (int) $fecha->format('N'), "{$fecha->format('Y-m-d')} deberia caer en lunes");
            }

            $this->assertTrue($ascension < $corpusChristi, 'Ascension debe ser antes que Corpus Christi');
            $this->assertTrue($corpusChristi < $sagradoCorazon, 'Corpus Christi debe ser antes que Sagrado Corazon');
        }
    }

    /** @param array<int, array{fecha:string,nombre:string,tipo:string}> $festivos @return array<string,string> */
    private function indexarPorNombre(array $festivos): array
    {
        $indice = [];
        foreach ($festivos as $f) {
            $indice[$f['nombre']] = $f['fecha'];
        }
        return $indice;
    }
}
