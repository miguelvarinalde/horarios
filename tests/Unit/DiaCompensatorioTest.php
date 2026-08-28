<?php

namespace Tests\Unit;

use App\Models\DiaCompensatorioModel;
use App\Services\CalculoRecargosService;
use Tests\TestCase;

/**
 * Casos de prueba de la opcion de dia compensatorio para trabajo
 * dominical/festivo (Ley 2466 de 2025): ocasional (hasta 2 domingos/festivos
 * trabajados en el mes calendario) permite elegir entre recargo o descanso;
 * habitual (3 o mas) da derecho a ambos, sin ser opcional.
 */
class DiaCompensatorioTest extends TestCase
{
    private CalculoRecargosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalculoRecargosService();
    }

    public function test_ocasional_por_defecto_es_solo_recargo(): void
    {
        $domingo = $this->domingosDelMes('2026-03')[0];
        $empleadoId = $this->crearEmpleado();

        // Vigencia acotada a un solo dia: solo ese domingo tiene horario, ningun otro domingo del mes.
        $this->asignarHorarioVigenciaAcotada($empleadoId, 0, $domingo, $domingo, [['08:00', '12:00']]);

        $periodoId = $this->crearPeriodo('2026-03-01', '2026-03-31');
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $resultado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $domingo);
        $this->assertEqualsWithDelta(4.0, $resultado['RDF'] ?? 0, 0.01, 'Por defecto (sin eleccion de RRHH) se paga el recargo, como antes');

        $dia = $this->obtenerDiaCompensatorio($empleadoId, $domingo);
        $this->assertSame('ocasional', $dia['clasificacion']);
        $this->assertSame('recargo', $dia['tratamiento']);
    }

    public function test_ocasional_eligiendo_descanso_compensatorio_no_genera_recargo(): void
    {
        $domingo = $this->domingosDelMes('2026-03')[1];
        $empleadoId = $this->crearEmpleado();

        $this->asignarHorarioVigenciaAcotada($empleadoId, 0, $domingo, $domingo, [['08:00', '12:00']]);

        $periodoId = $this->crearPeriodo('2026-03-01', '2026-03-31');
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $dia = $this->obtenerDiaCompensatorio($empleadoId, $domingo);
        DiaCompensatorioModel::actualizarTratamiento((int) $dia['id'], 'descanso_compensatorio', 'El empleado prefirio el dia libre');

        // RRHH debe recalcular el periodo para que la eleccion se refleje.
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $resultado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $domingo);
        $this->assertEqualsWithDelta(4.0, $resultado['ORD'] ?? 0, 0.01, 'Si eligio descanso, esas horas se pagan como ordinarias (sin recargo)');
        $this->assertArrayNotHasKey('RDF', $resultado);
    }

    public function test_habitual_reclasifica_retroactivamente_y_da_derecho_a_ambos(): void
    {
        $domingos = $this->domingosDelMes('2026-03');
        $this->assertGreaterThanOrEqual(3, count($domingos), 'Marzo 2026 deberia tener al menos 3 domingos');

        $empleadoId = $this->crearEmpleado();
        $periodoId = $this->crearPeriodo('2026-03-01', '2026-03-31');

        // Paso 1: el empleado trabaja solo los primeros dos domingos del mes -> ocasional.
        $this->asignarHorarioVigenciaAcotada($empleadoId, 0, $domingos[0], $domingos[0], [['08:00', '12:00']]);
        $this->asignarHorarioVigenciaAcotada($empleadoId, 0, $domingos[1], $domingos[1], [['08:00', '12:00']]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $dia1 = $this->obtenerDiaCompensatorio($empleadoId, $domingos[0]);
        $this->assertSame('ocasional', $dia1['clasificacion']);

        // Paso 2: trabaja un tercer domingo del mismo mes -> el mes pasa a habitual,
        // y los dos domingos anteriores deben reclasificarse tambien (no solo el nuevo).
        $this->asignarHorarioVigenciaAcotada($empleadoId, 0, $domingos[2], $domingos[2], [['08:00', '12:00']]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        foreach ([$domingos[0], $domingos[1], $domingos[2]] as $fecha) {
            $dia = $this->obtenerDiaCompensatorio($empleadoId, $fecha);
            $this->assertSame('habitual', $dia['clasificacion'], "El {$fecha} deberia quedar habitual tras el tercer domingo trabajado");
            $this->assertSame('ambos', $dia['tratamiento'], "El {$fecha} deberia dar derecho a recargo Y descanso (no es opcional)");

            $resultado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $fecha);
            $this->assertEqualsWithDelta(4.0, $resultado['RDF'] ?? 0, 0.01, "El {$fecha} sigue pagando recargo aun siendo habitual");
        }
    }
}
