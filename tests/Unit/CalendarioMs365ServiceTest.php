<?php
// tests/Unit/CalendarioMs365ServiceTest.php
namespace Tests\Unit;

use App\Models\EventoCalendarioMs365Model;
use App\Models\HorarioBaseModel;
use App\Services\CalendarioMs365Service;
use Tests\Fakes\FakeGraphCalendarClient;
use Tests\TestCase;

class CalendarioMs365ServiceTest extends TestCase
{
    private FakeGraphCalendarClient $cliente;
    private CalendarioMs365Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente = new FakeGraphCalendarClient();
        $this->service = new CalendarioMs365Service($this->cliente);

        // La integracion se activa globalmente vía configuracion_calendario_ms365
        // (fila unica). Se restaura al estado inactivo en tearDown para no
        // afectar otras pruebas ni el resto de la app.
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET tenant_id='t', client_id='c', client_secret='s', activo=1 WHERE id=1");
    }

    protected function tearDown(): void
    {
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET tenant_id=NULL, client_id=NULL, client_secret=NULL, activo=0 WHERE id=1");
        parent::tearDown();
    }

    public function test_sincronizarVigencia_crea_un_evento_recurrente_por_bloque(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado1@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]],
        ], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosCreados);
        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(1, $filas);
        $this->assertSame('sincronizado', $filas[0]['estado']);
        $this->assertSame(1, (int) $filas[0]['horario_dia_semana']);
        $this->assertSame(1, (int) $filas[0]['horario_orden']);
    }

    public function test_sincronizarVigencia_reutiliza_el_calendario_ya_creado_del_empleado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado2@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->calendariosPorEmail, 'Debe llamar a Graph una sola vez para obtener/crear el calendario, no una por evento');
    }

    public function test_sincronizarVigencia_agregar_un_bloque_crea_uno_nuevo_y_deja_el_existente(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado3@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        // Simula que el usuario edito la vigencia agregando un segundo bloque el mismo dia (turno partido).
        HorarioBaseModel::actualizarVigencia($empleadoId, '2026-01-01', null, null, [
            1 => [['hora_inicio' => '08:00', 'hora_fin' => '12:00'], ['hora_inicio' => '14:00', 'hora_fin' => '18:00']],
        ]);
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosActualizados, 'El bloque 1 (08-12) ya existia: se actualiza');
        $this->assertCount(2, $this->cliente->eventosCreados, 'El bloque 1 se creo en la primera sincronizacion y el bloque 2 (14-18) es nuevo en la segunda: 2 creaciones acumuladas');
        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $this->assertCount(2, $filas);
    }

    public function test_sincronizarVigencia_quitar_un_bloque_borra_su_evento_en_graph_y_su_fila(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado4@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00'], ['14:00', '18:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $this->assertCount(2, EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));

        HorarioBaseModel::actualizarVigencia($empleadoId, '2026-01-01', null, null, [
            1 => [['hora_inicio' => '08:00', 'hora_fin' => '12:00']],
        ]);
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(1, $this->cliente->eventosEliminados);
        $this->assertCount(1, EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }

    public function test_sincronizarVigencia_no_hace_nada_si_la_integracion_esta_inactiva(): void
    {
        $this->db->exec("UPDATE configuracion_calendario_ms365 SET activo=0 WHERE id=1");
        $empleadoId = $this->crearEmpleadoConCorreo('empleado5@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertEmpty($this->cliente->eventosCreados);
        $this->assertEmpty(EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }

    public function test_sincronizarVigencia_no_hace_nada_si_el_empleado_tiene_el_interruptor_apagado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado6@example.test', sincronizarCalendario: false);
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->assertEmpty($this->cliente->eventosCreados);
    }

    public function test_sincronizarVigencia_un_fallo_de_graph_deja_la_fila_en_error_sin_afectar_las_demas(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado7@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');

        // Fuerza que el primer evento creado falle: el fake asigna ids secuenciales, el primero sera 'ev-fake-1'.
        $this->cliente->fallarPara['ev-fake-1'] = true;

        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $filas = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01');
        $estados = array_column($filas, 'estado');
        sort($estados);
        $this->assertSame(['error', 'sincronizado'], $estados, 'Un evento en error no debe impedir que el otro se sincronice');
    }

    public function test_eliminarVigencia_borra_todos_los_eventos_de_esa_vigencia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado8@example.test');
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '12:00']]],
        ], '2026-01-01');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');

        $this->service->eliminarVigencia($empleadoId, '2026-01-01');

        $this->assertCount(2, $this->cliente->eventosEliminados);
        $this->assertEmpty(EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01'));
    }

    public function test_sincronizarNovedad_dia_completo_crea_un_evento_de_dia_completo(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad1@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-10');

        $this->service->sincronizarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosCreados);
        $this->assertTrue($this->cliente->eventosCreados[0]['payload']['isAllDay']);
        $fila = \App\Models\EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertSame('sincronizado', $fila['estado']);
    }

    public function test_sincronizarNovedad_parcial_crea_un_evento_con_hora(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad2@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'PERMISO_REMUNERADO', '2026-02-11', '11:00:00', '16:30:00');

        $this->service->sincronizarNovedad($novedadId);

        $payload = $this->cliente->eventosCreados[0]['payload'];
        $this->assertArrayNotHasKey('isAllDay', $payload);
        $this->assertStringContainsString('11:00:00', $payload['start']['dateTime']);
        $this->assertStringContainsString('16:30:00', $payload['end']['dateTime']);
    }

    public function test_sincronizarNovedad_llamada_dos_veces_actualiza_en_vez_de_duplicar(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad3@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-12');

        $this->service->sincronizarNovedad($novedadId);
        $this->service->sincronizarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosCreados);
        $this->assertCount(1, $this->cliente->eventosActualizados);
    }

    public function test_eliminarNovedad_borra_el_evento_y_la_fila_si_existia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad4@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-13');
        $this->service->sincronizarNovedad($novedadId);

        $this->service->eliminarNovedad($novedadId);

        $this->assertCount(1, $this->cliente->eventosEliminados);
        $this->assertNull(\App\Models\EventoCalendarioMs365Model::porNovedad($novedadId));
    }

    public function test_eliminarNovedad_no_hace_nada_si_nunca_se_habia_sincronizado(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-novedad5@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-14');

        $this->service->eliminarNovedad($novedadId);

        $this->assertEmpty($this->cliente->eventosEliminados);
    }

    public function test_reintentar_una_fila_de_horario_en_error_vuelve_a_sincronizar_toda_la_vigencia(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-reintento1@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');
        $this->cliente->fallarPara['ev-fake-1'] = true;
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $filaConError = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01')[0];
        $this->assertSame('error', $filaConError['estado']);

        unset($this->cliente->fallarPara['ev-fake-1']);
        $this->service->reintentar((int) $filaConError['id']);

        $filaActualizada = EventoCalendarioMs365Model::porVigencia($empleadoId, '2026-01-01')[0];
        $this->assertSame('sincronizado', $filaActualizada['estado']);
    }

    public function test_reintentar_una_fila_de_novedad_en_error_la_vuelve_a_sincronizar(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-reintento2@example.test');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-20');
        $this->cliente->fallarPara['ev-fake-1'] = true;
        $this->service->sincronizarNovedad($novedadId);
        $filaConError = EventoCalendarioMs365Model::porNovedad($novedadId);
        $this->assertSame('error', $filaConError['estado']);

        unset($this->cliente->fallarPara['ev-fake-1']);
        $this->service->reintentar((int) $filaConError['id']);

        $this->assertSame('sincronizado', EventoCalendarioMs365Model::porNovedad($novedadId)['estado']);
    }

    public function test_eliminarTodosLosEventosDelEmpleado_borra_horarios_y_novedades(): void
    {
        $empleadoId = $this->crearEmpleadoConCorreo('empleado-borrartodo@example.test');
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]]], '2026-01-01');
        $novedadId = $this->crearNovedadAprobada($empleadoId, 'VACACIONES', '2026-02-21');
        $this->service->sincronizarVigencia($empleadoId, '2026-01-01');
        $this->service->sincronizarNovedad($novedadId);

        $this->service->eliminarTodosLosEventosDelEmpleado($empleadoId);

        $this->assertCount(2, $this->cliente->eventosEliminados);
        $this->assertEmpty(EventoCalendarioMs365Model::porEmpleado($empleadoId));
    }
}
