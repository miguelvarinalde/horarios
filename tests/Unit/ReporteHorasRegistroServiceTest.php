<?php

namespace Tests\Unit;

use App\Services\ReporteHorasRegistroService;
use Tests\TestCase;

/**
 * Casos del informe "Horas trabajadas segun registro" (auditoria, basado en
 * marcaciones reales). Se enfoca en el cierre automatico de salidas
 * olvidadas y en como debe tener en cuenta los permisos/novedades
 * aprobados — el caso real que motivo esto (2026-09-16, produccion): un
 * empleado sin horario_base marco entrada y tenia un permiso aprobado para
 * ausentarse el resto del dia; el cierre automatico le contaba esas horas
 * como trabajadas por no conocer el permiso (ver docblock de
 * ReporteHorasRegistroService::calcularSalidaAutomatica()).
 */
class ReporteHorasRegistroServiceTest extends TestCase
{
    private ReporteHorasRegistroService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReporteHorasRegistroService();
        // Configuracion propia, vigente para todas las fechas de prueba de
        // esta clase (2020, muy anterior a cualquier fecha real usada en
        // otras pruebas o en produccion) — sin almuerzo activo, para que los
        // calculos de horas no dependan de la configuracion vigente
        // "actual" sembrada (ver docblock de CalculoRecargosServiceTest).
        $this->crearConfiguracionGlobal('2019-01-01', 44.0);
    }

    public function test_cierre_automatico_respeta_permiso_aprobado_que_empieza_despues_de_la_entrada(): void
    {
        $empleadoId = $this->crearEmpleado();
        $fecha = '2020-01-15'; // dia ya terminado, sin horario_base asignado

        $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 06:42:00");
        $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', $fecha, '11:00:00', '16:30:00');

        $informe = $this->service->generarInforme($empleadoId, $fecha, $fecha);
        $dia = $informe[0];

        $this->assertSame('cerrado_automatico', $dia['estado']);
        $this->assertSame('11:00:00', $dia['salida_estimada']);
        $this->assertEqualsWithDelta(4.3, $dia['horas_totales'], 0.01, 'Solo debe contar de 06:42 a 11:00 (inicio del permiso), no 8h completas');
        $this->assertStringContainsString('permiso aprobado', $dia['nota']);
    }

    public function test_cierre_automatico_ignora_permiso_no_aprobado(): void
    {
        $empleadoId = $this->crearEmpleado();
        $fecha = '2020-01-15';

        $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 06:42:00");
        $this->crearNovedadConEstado($empleadoId, 'PERMISO_REMUNERADO', $fecha, 'pendiente', '11:00:00', '16:30:00');

        $informe = $this->service->generarInforme($empleadoId, $fecha, $fecha);
        $dia = $informe[0];

        $this->assertSame('cerrado_automatico', $dia['estado']);
        $this->assertNotSame('11:00:00', $dia['salida_estimada'], 'Una novedad pendiente (no aprobada) no debe cambiar el cierre automatico');
        $this->assertEqualsWithDelta(8.0, $dia['horas_totales'], 0.01, 'Sin horario ni permiso aprobado, se asumen 8h de jornada continua');
    }

    public function test_cierre_automatico_ignora_permiso_que_ya_termino_antes_de_la_entrada(): void
    {
        $empleadoId = $this->crearEmpleado();
        $fecha = '2020-01-15';

        // Entrada tardia (14:00): un permiso de la manana (08:00-10:00) ya
        // paso antes de esta entrada y no explica una salida sin marcar.
        $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 14:00:00");
        $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', $fecha, '08:00:00', '10:00:00');

        $informe = $this->service->generarInforme($empleadoId, $fecha, $fecha);
        $dia = $informe[0];

        $this->assertSame('cerrado_automatico', $dia['estado']);
        $this->assertNotSame('08:00:00', $dia['salida_estimada']);
        // Entrada a las 14:00 (>= 13:00): jornada continua asumida de 7h -> cierre 21:00.
        $this->assertSame('21:00:00', $dia['salida_estimada']);
    }

    public function test_informe_expone_las_novedades_del_dia_sin_importar_su_estado(): void
    {
        $empleadoId = $this->crearEmpleado();
        $fecha = '2020-01-20';

        $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 08:00:00");
        $this->crearMarcacion($empleadoId, 'salida', "{$fecha} 16:00:00");
        $this->crearNovedadConEstado($empleadoId, 'PERMISO_NO_REMUNERADO', $fecha, 'rechazado', null, null);

        $informe = $this->service->generarInforme($empleadoId, $fecha, $fecha);
        $dia = $informe[0];

        $this->assertSame('completo', $dia['estado'], 'Una novedad rechazada no debe afectar un dia con marcaciones completas');
        $this->assertCount(1, $dia['novedades']);
        $this->assertSame('rechazado', $dia['novedades'][0]['estado']);
        $this->assertSame('Permiso no remunerado', $dia['novedades'][0]['tipo_nombre']);
    }
}
