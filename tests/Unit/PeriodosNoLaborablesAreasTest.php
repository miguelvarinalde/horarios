<?php

namespace Tests\Unit;

use App\Models\PeriodoNoLaborableModel;
use App\Services\CalculoRecargosService;
use Tests\TestCase;

/**
 * Alcance por area de los periodos no laborables: sin areas = toda la
 * empresa (comportamiento historico); con areas = solo los empleados de
 * esas areas; un empleado sin area solo se ve afectado por los de toda la
 * empresa.
 */
class PeriodosNoLaborablesAreasTest extends TestCase
{
    private CalculoRecargosService $service;
    /** @var int[] */
    private array $periodos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalculoRecargosService();
    }

    protected function tearDown(): void
    {
        foreach ($this->periodos as $id) {
            $this->eliminarPeriodoNoLaborable($id);
        }
        parent::tearDown();
    }

    /**
     * Tres empleados con el mismo horario (miercoles 08:00-16:00): uno del
     * area A, uno del area B y uno sin area. Devuelve las horas del miercoles de cada uno.
     *
     * @param int[] $areasDelPeriodo indices 0 (area A) y/o 1 (area B); vacio = toda la empresa
     * @return array{a: float, b: float, sinArea: float}
     */
    private function horasDelMiercoles(array $areasDelPeriodo): array
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $miercoles = $semana[3];
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $areas = [$this->crearArea(), $this->crearArea()];
        $empleados = ['a' => $this->crearEmpleado(), 'b' => $this->crearEmpleado(), 'sinArea' => $this->crearEmpleado()];
        $this->asignarArea($empleados['a'], $areas[0]);
        $this->asignarArea($empleados['b'], $areas[1]);

        $this->periodos[] = $this->crearPeriodoNoLaborable($miercoles, $miercoles, array_map(fn ($i) => $areas[$i], $areasDelPeriodo));

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $horas = [];
        foreach ($empleados as $clave => $empleadoId) {
            $this->asignarHorario($empleadoId, [['dia_semana' => 3, 'bloques' => [['08:00', '16:00']]]]);
            $this->service->calcularPeriodo($empleadoId, $periodoId);
            $horas[$clave] = array_sum($this->horasPorTipoRecargo($empleadoId, $periodoId, $miercoles));
        }
        return $horas;
    }

    public function test_periodo_sin_areas_sigue_siendo_de_toda_la_empresa(): void
    {
        $h = $this->horasDelMiercoles([]);

        $this->assertEqualsWithDelta(0.0, $h['a'], 0.01);
        $this->assertEqualsWithDelta(0.0, $h['b'], 0.01);
        $this->assertEqualsWithDelta(0.0, $h['sinArea'], 0.01, 'Toda la empresa incluye a quien no tiene area');
    }

    public function test_periodo_de_un_area_solo_suspende_a_los_empleados_de_esa_area(): void
    {
        $h = $this->horasDelMiercoles([0]);

        $this->assertEqualsWithDelta(0.0, $h['a'], 0.01, 'El area A esta en vacaciones');
        $this->assertGreaterThan(7.0, $h['b'], 'El area B trabaja normal');
        $this->assertGreaterThan(7.0, $h['sinArea'], 'Un empleado sin area no se ve afectado por un periodo de area');
    }

    public function test_periodo_de_varias_areas_suspende_a_todas_ellas(): void
    {
        $h = $this->horasDelMiercoles([0, 1]);

        $this->assertEqualsWithDelta(0.0, $h['a'], 0.01);
        $this->assertEqualsWithDelta(0.0, $h['b'], 0.01);
        $this->assertGreaterThan(7.0, $h['sinArea']);
    }

    public function test_el_modelo_sincroniza_areas_y_aplica_a(): void
    {
        $areaA = $this->crearArea();
        $areaB = $this->crearArea();
        $id = $this->crearPeriodoNoLaborable('2026-06-03', '2026-06-03');
        $this->periodos[] = $id;

        $this->assertSame([], PeriodoNoLaborableModel::areaIds($id));

        PeriodoNoLaborableModel::sincronizarAreas($id, [$areaA, $areaB, $areaA]);
        $this->assertSame([min($areaA, $areaB), max($areaA, $areaB)], PeriodoNoLaborableModel::areaIds($id));
        $this->assertSame('area', PeriodoNoLaborableModel::find($id)['aplica_a']);

        PeriodoNoLaborableModel::sincronizarAreas($id, []);
        $this->assertSame([], PeriodoNoLaborableModel::areaIds($id));
        $this->assertSame('empresa', PeriodoNoLaborableModel::find($id)['aplica_a']);
    }

    public function test_conAreas_filtra_por_rango_y_trae_los_nombres(): void
    {
        $area = $this->crearArea();
        $id = $this->crearPeriodoNoLaborable('2026-06-03', '2026-06-05', [$area]);
        $this->periodos[] = $id;

        $encontrados = array_filter(PeriodoNoLaborableModel::conAreas('2026-06-01', '2026-06-07'), fn ($p) => (int) $p['id'] === $id);
        $this->assertCount(1, $encontrados);
        $this->assertCount(1, reset($encontrados)['areas']);

        $fuera = array_filter(PeriodoNoLaborableModel::conAreas('2026-07-01', '2026-07-07'), fn ($p) => (int) $p['id'] === $id);
        $this->assertCount(0, $fuera);
    }
}
