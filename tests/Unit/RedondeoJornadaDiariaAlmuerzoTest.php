<?php

namespace Tests\Unit;

use App\Services\CalculoRecargosService;
use App\Services\ReporteHorasRegistroService;
use Tests\TestCase;

/**
 * Reglas de 2026-10-30 (revision de la semana 19-25 de octubre de un colaborador):
 *  - redondeo de marcaciones configurable (hoy 15 min), al multiplo mas cercano y con el
 *    empate a favor del colaborador (la entrada baja, la salida sube);
 *  - tope de horas ORDINARIAS por dia (jornada flexible, 9 h): lo que lo supere es extra
 *    aunque la semana no llegue a la jornada semanal, tanto en el informe por registro
 *    como en el motor legal por horario;
 *  - alertas cuando las horas extra superan el limite legal (2 al dia, 12 a la semana);
 *  - novedad aprobada "Almuerzo no tomado": evita el descuento automatico de almuerzo.
 *
 * Las fechas de 2020 no chocan con las vigencias reales de configuracion_global (desde 2023).
 */
class RedondeoJornadaDiariaAlmuerzoTest extends TestCase
{
    private ReporteHorasRegistroService $informe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->informe = new ReporteHorasRegistroService();
    }

    /** @return array<string, mixed> el unico dia del informe */
    private function dia(int $empleadoId, string $fecha, string $entrada, string $salida): array
    {
        $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} {$entrada}");
        $this->crearMarcacion($empleadoId, 'salida', "{$fecha} {$salida}");
        return $this->informe->generarInforme($empleadoId, $fecha, $fecha)[0];
    }

    /** @return array<string, float> codigo => horas del desglose (exacto o redondeado) */
    private function desglose(array $dia, string $clave = 'desglose'): array
    {
        $mapa = [];
        foreach ($dia[$clave] as $d) {
            $mapa[$d['codigo']] = round($d['horas'], 4);
        }
        return $mapa;
    }

    // --- Redondeo ---------------------------------------------------------

    public function test_redondeo_a_15_minutos_lleva_cada_marcacion_al_multiplo_mas_cercano(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 15);
        $empleadoId = $this->crearEmpleado();

        // Los casos reales de la semana revisada (entrada, salida => entrada y salida redondeadas).
        $casos = [
            ['2020-03-02', '06:48:00', '14:01:00', '06:45:00', '14:00:00'],
            ['2020-03-03', '06:53:00', '14:34:00', '07:00:00', '14:30:00'],
            ['2020-03-04', '06:38:00', '13:27:00', '06:45:00', '13:30:00'],
            ['2020-03-05', '12:30:00', '20:34:00', '12:30:00', '20:30:00'],
        ];
        foreach ($casos as [$fecha, $entrada, $salida, $entradaEsperada, $salidaEsperada]) {
            $dia = $this->dia($empleadoId, $fecha, $entrada, $salida);
            $this->assertSame($entradaEsperada, $dia['primera_entrada_redondeada'], "entrada {$entrada}");
            $this->assertSame($salidaEsperada, $dia['ultima_salida_redondeada'], "salida {$salida}");
            $this->assertSame(15, $dia['redondeo_minutos']);
        }
    }

    public function test_empate_exacto_del_redondeo_favorece_al_colaborador(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 15);
        $empleadoId = $this->crearEmpleado();

        // 7 min 30 s: justo en medio. La entrada baja (mas tiempo trabajado) y la salida sube.
        $dia = $this->dia($empleadoId, '2020-03-02', '07:07:30', '15:07:30');
        $this->assertSame('07:00:00', $dia['primera_entrada_redondeada']);
        $this->assertSame('15:15:00', $dia['ultima_salida_redondeada']);

        $dia = $this->dia($empleadoId, '2020-03-03', '07:22:30', '15:22:30');
        $this->assertSame('07:15:00', $dia['primera_entrada_redondeada']);
        $this->assertSame('15:30:00', $dia['ultima_salida_redondeada']);

        // Un segundo antes del empate ya no favorece: 07:07:29 esta mas cerca de 07:00 (entrada) y 15:07:31 de 15:15.
        $dia = $this->dia($empleadoId, '2020-03-04', '07:07:31', '15:07:29');
        $this->assertSame('07:15:00', $dia['primera_entrada_redondeada']);
        $this->assertSame('15:00:00', $dia['ultima_salida_redondeada']);
    }

    public function test_redondeo_cero_deja_las_horas_exactas(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, null, 0);
        $empleadoId = $this->crearEmpleado();

        $dia = $this->dia($empleadoId, '2020-03-02', '06:48:10', '14:01:20');
        $this->assertSame('06:48:10', $dia['primera_entrada_redondeada']);
        $this->assertSame('14:01:20', $dia['ultima_salida_redondeada']);
        $this->assertEqualsWithDelta($dia['horas_totales'], $dia['horas_totales_redondeadas'], 0.01);
    }

    public function test_horas_redondeadas_usan_el_bloque_configurado_tramo_por_tramo(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00', 9.0, 15);
        $empleadoId = $this->crearEmpleado();

        // 06:48-14:01 con almuerzo descontado: tramos 06:48-12:00 y 13:00-14:01 -> 06:45-12:00 (5.25) y 13:00-14:00 (1.0).
        $dia = $this->dia($empleadoId, '2020-03-02', '06:48:00', '14:01:00');
        $this->assertEqualsWithDelta(6.22, $dia['horas_totales'], 0.01);
        $this->assertEqualsWithDelta(6.25, $dia['horas_totales_redondeadas'], 0.001);
    }

    // --- Tope diario ------------------------------------------------------

    public function test_lo_que_pasa_de_9_horas_en_un_dia_es_extra_aunque_la_semana_no_llegue_al_limite(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 0);
        $empleadoId = $this->crearEmpleado();

        $dia = $this->dia($empleadoId, '2020-03-02', '06:00:00', '19:00:00'); // 13 h, la semana apenas empieza
        $horas = $this->desglose($dia);

        $this->assertSame(9.0, $horas['ORD']);
        $this->assertSame(4.0, $horas['HED']);
        $this->assertEqualsWithDelta(4.0, $dia['horas_extra'], 0.001);
    }

    public function test_sin_tope_diario_solo_cuenta_la_semana_como_antes(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, null, 0);
        $empleadoId = $this->crearEmpleado();

        $dia = $this->dia($empleadoId, '2020-03-02', '06:00:00', '19:00:00');
        $horas = $this->desglose($dia);

        $this->assertSame(13.0, $horas['ORD']);
        $this->assertArrayNotHasKey('HED', $horas);
        $this->assertSame([], $dia['alertas']);
    }

    public function test_las_extras_por_tope_diario_no_gastan_la_capacidad_semanal(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 0);
        $empleadoId = $this->crearEmpleado();

        // Lunes 13 h (9 ordinarias + 4 extra) y martes a viernes 9 h: ordinarias = 9 + 36 = 45 > 44,
        // asi que solo el viernes pierde 1 h. Si las 4 extras del lunes tambien gastaran semana, el
        // viernes tendria 5 h extra en vez de 1.
        $this->crearMarcacion($empleadoId, 'entrada', '2020-03-02 06:00:00');
        $this->crearMarcacion($empleadoId, 'salida', '2020-03-02 19:00:00');
        foreach (['2020-03-03', '2020-03-04', '2020-03-05', '2020-03-06'] as $fecha) {
            $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 06:00:00");
            $this->crearMarcacion($empleadoId, 'salida', "{$fecha} 15:00:00");
        }

        $informe = $this->informe->generarInforme($empleadoId, '2020-03-02', '2020-03-06');
        $viernes = $this->desglose($informe[4]);

        $this->assertSame('2020-03-06', $informe[4]['fecha']);
        $this->assertSame(8.0, $viernes['ORD']);
        $this->assertSame(1.0, $viernes['HED']);
    }

    // --- Alertas de limite de horas extra ----------------------------------

    public function test_alerta_cuando_las_extras_del_dia_superan_dos_horas(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 0);
        $empleadoId = $this->crearEmpleado();

        $diaLargo = $this->dia($empleadoId, '2020-03-02', '06:00:00', '19:00:00'); // 4 h extra
        $this->assertCount(1, $diaLargo['alertas']);
        $this->assertStringContainsString('diarias', $diaLargo['alertas'][0]);

        $diaNormal = $this->dia($empleadoId, '2020-03-03', '06:00:00', '17:00:00'); // 2 h extra: justo en el limite
        $this->assertSame([], $diaNormal['alertas']);
    }

    public function test_alerta_semanal_una_sola_vez_al_cruzar_las_12_horas_extra(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', null, null, 9.0, 0);
        $empleadoId = $this->crearEmpleado();

        foreach (['2020-03-02', '2020-03-03', '2020-03-04', '2020-03-05', '2020-03-06'] as $fecha) {
            $this->crearMarcacion($empleadoId, 'entrada', "{$fecha} 06:00:00");
            $this->crearMarcacion($empleadoId, 'salida', "{$fecha} 18:00:00"); // 12 h: 3 extra cada dia
        }

        $informe = $this->informe->generarInforme($empleadoId, '2020-03-02', '2020-03-06');
        $conAlertaSemanal = [];
        foreach ($informe as $dia) {
            foreach ($dia['alertas'] as $alerta) {
                if (str_contains($alerta, 'semanales')) {
                    $conAlertaSemanal[] = $dia['fecha'];
                }
            }
        }

        // Extras acumuladas: 3, 6, 9, 12, y el viernes (con 4 h extra) llega a 16 > 12.
        $this->assertSame(['2020-03-06'], $conAlertaSemanal);
    }

    // --- Almuerzo no tomado -----------------------------------------------

    public function test_novedad_aprobada_de_almuerzo_no_tomado_evita_el_descuento(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00', 9.0, 15);
        $empleadoId = $this->crearEmpleado();

        // Sin novedad: se descuenta la hora de almuerzo (07:00-14:00 -> 6 h).
        $base = $this->dia($empleadoId, '2020-03-02', '07:00:00', '14:00:00');
        $this->assertEqualsWithDelta(6.0, $base['horas_totales'], 0.001);
        $this->assertEqualsWithDelta(1.0, $base['almuerzo_descontado_horas'], 0.001);
        $this->assertFalse($base['almuerzo_no_tomado']);

        // Con la novedad aprobada: cuenta la jornada completa.
        $this->crearNovedadAprobada($empleadoId, 'ALMUERZO_NO_TOMADO', '2020-03-03');
        $exento = $this->dia($empleadoId, '2020-03-03', '07:00:00', '14:00:00');
        $this->assertEqualsWithDelta(7.0, $exento['horas_totales'], 0.001);
        $this->assertEqualsWithDelta(7.0, $exento['horas_totales_redondeadas'], 0.001);
        $this->assertEqualsWithDelta(0.0, $exento['almuerzo_descontado_horas'], 0.001);
        $this->assertTrue($exento['almuerzo_no_tomado']);
    }

    public function test_novedad_pendiente_o_rechazada_de_almuerzo_no_tomado_no_evita_el_descuento(): void
    {
        $this->crearConfiguracionGlobal('2020-03-01', 44.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00', 9.0, 15);
        $empleadoId = $this->crearEmpleado();

        $this->crearNovedadConEstado($empleadoId, 'ALMUERZO_NO_TOMADO', '2020-03-02', 'pendiente');
        $this->crearNovedadConEstado($empleadoId, 'ALMUERZO_NO_TOMADO', '2020-03-03', 'rechazado');

        foreach (['2020-03-02', '2020-03-03'] as $fecha) {
            $dia = $this->dia($empleadoId, $fecha, '07:00:00', '14:00:00');
            $this->assertEqualsWithDelta(6.0, $dia['horas_totales'], 0.001, "novedad no aprobada el {$fecha}");
            $this->assertFalse($dia['almuerzo_no_tomado']);
        }
    }

    // --- Motor legal por horario --------------------------------------------

    public function test_motor_legal_aplica_el_tope_diario_de_9_horas(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $this->crearConfiguracionGlobal($semana[1], 42.0, '21:00:00', '06:00:00', null, null, 9.0, 15);

        $conTope = $this->crearEmpleado();
        $this->asignarHorario($conTope, [['dia_semana' => 1, 'bloques' => [['06:00', '19:00']]]]);
        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        (new CalculoRecargosService())->calcularPeriodo($conTope, $periodoId);

        $horas = $this->horasPorTipoRecargo($conTope, $periodoId);
        $this->assertEqualsWithDelta(9.0, $horas['ORD'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(4.0, $horas['HED'] ?? 0, 0.01);
    }

    public function test_motor_legal_sin_tope_diario_no_cambia(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $this->crearConfiguracionGlobal($semana[1], 42.0); // sin tope diario

        $empleadoId = $this->crearEmpleado();
        $this->asignarHorario($empleadoId, [['dia_semana' => 1, 'bloques' => [['06:00', '19:00']]]]);
        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        (new CalculoRecargosService())->calcularPeriodo($empleadoId, $periodoId);

        $horas = $this->horasPorTipoRecargo($empleadoId, $periodoId);
        $this->assertEqualsWithDelta(13.0, $horas['ORD'] ?? 0, 0.01);
        $this->assertArrayNotHasKey('HED', $horas);
    }

    public function test_motor_legal_respeta_la_novedad_de_almuerzo_no_tomado(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $this->crearConfiguracionGlobal($semana[1], 42.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00', 9.0, 15);
        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $service = new CalculoRecargosService();

        $sinNovedad = $this->crearEmpleado();
        $this->asignarHorario($sinNovedad, [['dia_semana' => 1, 'bloques' => [['07:00', '14:00']]]]);
        $service->calcularPeriodo($sinNovedad, $periodoId);
        $this->assertEqualsWithDelta(6.0, $this->horasPorTipoRecargo($sinNovedad, $periodoId)['ORD'] ?? 0, 0.01);

        $conNovedad = $this->crearEmpleado();
        $this->asignarHorario($conNovedad, [['dia_semana' => 1, 'bloques' => [['07:00', '14:00']]]]);
        $this->crearNovedadAprobada($conNovedad, 'ALMUERZO_NO_TOMADO', $semana[1]);
        $service->calcularPeriodo($conNovedad, $periodoId);
        $this->assertEqualsWithDelta(7.0, $this->horasPorTipoRecargo($conNovedad, $periodoId)['ORD'] ?? 0, 0.01);
    }
}
