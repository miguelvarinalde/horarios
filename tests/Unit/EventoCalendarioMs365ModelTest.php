<?php
// tests/Unit/EventoCalendarioMs365ModelTest.php
namespace Tests\Unit;

use App\Models\EventoCalendarioMs365Model;
use Tests\TestCase;

class EventoCalendarioMs365ModelTest extends TestCase
{
    public function test_guardarHorario_crea_y_luego_actualiza_por_llave_natural(): void
    {
        $empleadoId = $this->crearEmpleado();

        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-01 08:00:00',
        ]);

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas);
        $this->assertSame('ev-1', $filas[0]['ms_event_id']);

        // Segunda llamada con la MISMA llave natural (empleado/vigencia/dia/orden) actualiza, no duplica.
        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-1-actualizado',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-02 08:00:00',
        ]);

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas, 'No debe duplicar la fila al reinsertar la misma llave natural');
        $this->assertSame('ev-1-actualizado', $filas[0]['ms_event_id']);
    }

    public function test_guardarNovedad_y_porNovedad(): void
    {
        $empleadoId = $this->crearEmpleado();
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', '2026-01-05');

        $this->assertNull(EventoCalendarioMs365Model::porNovedad($novedadId));

        EventoCalendarioMs365Model::guardarNovedad([
            'empleado_id' => $empleadoId,
            'novedad_id' => $novedadId,
            'ms_calendar_id' => 'cal-1',
            'ms_event_id' => 'ev-novedad-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-05 08:00:00',
        ]);

        $fila = EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertNotNull($fila);
        $this->assertSame('ev-novedad-1', $fila['ms_event_id']);
    }

    public function test_calendarioIdDeEmpleado_devuelve_null_sin_filas_y_el_id_una_vez_hay_una(): void
    {
        $empleadoId = $this->crearEmpleado();
        $this->assertNull(EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId));

        EventoCalendarioMs365Model::guardarHorario([
            'empleado_id' => $empleadoId,
            'horario_vigente_desde' => '2026-01-01',
            'horario_dia_semana' => 1,
            'horario_orden' => 1,
            'ms_calendar_id' => 'cal-reutilizable',
            'ms_event_id' => 'ev-1',
            'estado' => 'sincronizado',
            'error_mensaje' => null,
            'sincronizado_at' => '2026-01-01 08:00:00',
        ]);

        $this->assertSame('cal-reutilizable', EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId));
    }
}
