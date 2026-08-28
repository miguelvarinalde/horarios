<?php

namespace Tests\Unit;

use App\Services\CalculoRecargosService;
use Tests\TestCase;

/**
 * Casos de prueba del motor de calculo, uno por cada escenario legal
 * distinto descrito en el plan. Las fechas se calculan en tiempo de
 * ejecucion (ver TestCase::obtenerSemana) a partir de un ancla fija, nunca
 * se hardcodea a mano que dia de la semana cae una fecha.
 *
 * Requiere que la base de datos configurada en .env tenga aplicados
 * migrate.php + seed.php (catalogos tipos_recargo/tipos_novedad/roles).
 * Cada prueba fija su propia configuracion_global (jornada + ventana
 * nocturna) para la semana que usa, en vez de depender del valor "actual"
 * sembrado — asi las pruebas no se rompen cuando la jornada legal real
 * cambia con el tiempo (ver database/seeds/006_configuracion_global.sql).
 */
class CalculoRecargosServiceTest extends TestCase
{
    private CalculoRecargosService $service;

    /** @var string[] */
    private array $festivosCreados = [];
    /** @var int[] */
    private array $periodosNoLaborablesCreados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalculoRecargosService();
    }

    protected function tearDown(): void
    {
        foreach ($this->festivosCreados as $fecha) {
            $this->eliminarFestivo($fecha);
        }
        foreach ($this->periodosNoLaborablesCreados as $id) {
            $this->eliminarPeriodoNoLaborable($id);
        }
        parent::tearDown();
    }

    public function test_hora_extra_diurna_por_superar_jornada_semanal(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $dias = [];
        foreach ([1, 2, 3, 4, 5] as $diaSemana) { // lunes a viernes, 8h c/u = 40h
            $dias[] = ['dia_semana' => $diaSemana, 'bloques' => [['08:00', '16:00']]];
        }
        $dias[] = ['dia_semana' => 6, 'bloques' => [['08:00', '14:00']]]; // sabado 6h -> 40+6=46h
        $this->asignarHorario($empleadoId, $dias);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $sabado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[6]);

        $this->assertEqualsWithDelta(2.0, $sabado['ORD'] ?? 0, 0.01, 'Las primeras 2h del sabado completan la jornada ordinaria (40+2=42)');
        $this->assertEqualsWithDelta(4.0, $sabado['HED'] ?? 0, 0.01, 'Las siguientes 4h del sabado son hora extra diurna');
    }

    public function test_hora_extra_nocturna_por_superar_jornada_semanal(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $dias = [];
        foreach ([1, 2, 3, 4] as $diaSemana) { // lunes a jueves, 8h c/u = 32h
            $dias[] = ['dia_semana' => $diaSemana, 'bloques' => [['08:00', '16:00']]];
        }
        $dias[] = ['dia_semana' => 5, 'bloques' => [['08:00', '18:00']]]; // viernes 10h -> 42h exactos
        $dias[] = ['dia_semana' => 6, 'bloques' => [['20:00', '23:00']]]; // sabado 3h, todo extra
        $this->asignarHorario($empleadoId, $dias);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $sabado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[6]);

        $this->assertEqualsWithDelta(1.0, $sabado['HED'] ?? 0, 0.01, '20:00-21:00 es extra diurna (aun no entra la ventana nocturna)');
        $this->assertEqualsWithDelta(2.0, $sabado['HEN'] ?? 0, 0.01, '21:00-23:00 es extra nocturna');
    }

    public function test_recargo_nocturno_ordinario(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['21:00', '23:00']]], // 2h, totalmente dentro de la ventana nocturna
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $lunes = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[1]);

        $this->assertEqualsWithDelta(2.0, $lunes['RN'] ?? 0, 0.01, 'Dentro de jornada pactada y muy por debajo de las 42h/semana: recargo nocturno ordinario, no hora extra');
        $this->assertArrayNotHasKey('HEN', $lunes);
    }

    public function test_recargo_dominical_festivo_ordinario(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 0, 'bloques' => [['08:00', '12:00']]], // domingo, 4h
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $domingo = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[0]);

        $this->assertEqualsWithDelta(4.0, $domingo['RDF'] ?? 0, 0.01, 'Trabajo ordinario en domingo: recargo dominical/festivo diurno (75%)');
    }

    public function test_hora_extra_diurna_dominical_festiva(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $dias = [];
        foreach ([1, 2, 3, 4, 5] as $diaSemana) {
            $dias[] = ['dia_semana' => $diaSemana, 'bloques' => [['08:00', '16:00']]]; // 40h
        }
        $dias[] = ['dia_semana' => 6, 'bloques' => [['08:00', '10:00']]]; // sabado 2h -> 42h exactos
        $dias[] = ['dia_semana' => 0, 'bloques' => [['08:00', '10:00']]]; // domingo 2h, todo extra + dominical
        $this->asignarHorario($empleadoId, $dias);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $sabado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[6]);
        $domingo = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[0]);

        $this->assertEqualsWithDelta(2.0, $sabado['ORD'] ?? 0, 0.01, 'El sabado completa exactamente las 42h, sigue siendo ordinario');
        $this->assertEqualsWithDelta(2.0, $domingo['HEDDF'] ?? 0, 0.01, 'El domingo ya no hay capacidad ordinaria: hora extra diurna + dominical/festiva combinadas');
    }

    public function test_hora_extra_nocturna_dominical_festiva(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $dias = [];
        foreach ([1, 2, 3, 4, 5] as $diaSemana) {
            $dias[] = ['dia_semana' => $diaSemana, 'bloques' => [['08:00', '16:00']]]; // 40h
        }
        $dias[] = ['dia_semana' => 6, 'bloques' => [['08:00', '10:00']]]; // sabado 2h -> 42h exactos
        $dias[] = ['dia_semana' => 0, 'bloques' => [['20:00', '22:00']]]; // domingo 2h, cruza ventana nocturna, todo extra
        $this->asignarHorario($empleadoId, $dias);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $domingo = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[0]);

        $this->assertEqualsWithDelta(1.0, $domingo['HEDDF'] ?? 0, 0.01, '20:00-21:00: extra diurna + dominical/festiva');
        $this->assertEqualsWithDelta(1.0, $domingo['HENDF'] ?? 0, 0.01, '21:00-22:00: extra nocturna + dominical/festiva');
    }

    public function test_turno_partido_que_cruza_la_ventana_nocturna(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        // Turno partido real (dos bloques) + el segundo bloque cruza la ventana nocturna (21:00).
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00'], ['14:00', '22:00']]],
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $lunes = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[1]);

        // Bloque 1: 4h diurnas. Bloque 2: 14:00-21:00 (7h diurnas) + 21:00-22:00 (1h nocturna).
        $this->assertEqualsWithDelta(11.0, $lunes['ORD'] ?? 0, 0.01, '4h (bloque 1) + 7h (bloque 2 antes de las 21:00) = 11h ordinarias diurnas');
        $this->assertEqualsWithDelta(1.0, $lunes['RN'] ?? 0, 0.01, 'La ultima hora del segundo bloque (21:00-22:00) es recargo nocturno');
    }

    public function test_periodo_no_laborable_y_festivo_no_duplican_recargo(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $miercoles = $semana[3];
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        $this->festivosCreados[] = $miercoles;
        $this->crearFestivoManual($miercoles, 'Festivo de prueba');
        $this->periodosNoLaborablesCreados[] = $this->crearPeriodoNoLaborable($miercoles, $miercoles);

        // El horario base de ese dia NO deberia contar (el periodo no laborable lo suspende).
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 3, 'bloques' => [['08:00', '16:00']]],
        ]);

        // Pero si el empleado SI trabajo ese dia (novedad festivo_trabajado), esas horas si cuentan,
        // clasificadas una sola vez como dominical/festivo (no se duplica por tambien ser periodo no laborable).
        $this->crearNovedadAprobada($empleadoId, 'FESTIVO_TRABAJADO', $miercoles, '09:00', '13:00');

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $resultado = $this->horasPorTipoRecargo($empleadoId, $periodoId, $miercoles);

        $this->assertEqualsWithDelta(4.0, $resultado['RDF'] ?? 0, 0.01, 'Solo las 4h de la novedad cuentan, como RDF, una sola vez');
        $totalHoras = array_sum($resultado);
        $this->assertEqualsWithDelta(4.0, $totalHoras, 0.01, 'El horario base (8h) no debio contarse: el periodo no laborable lo suspende');
    }

    public function test_almuerzo_se_descuenta_automaticamente_en_bloque_continuo(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00');

        // Un solo bloque continuo de 9h (08:00-17:00) SIN turno partido.
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '17:00']]],
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $lunes = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[1]);
        $total = array_sum($lunes);

        $this->assertEqualsWithDelta(8.0, $total, 0.01, 'Las 9h del bloque menos 1h de almuerzo (12:00-13:00) = 8h trabajadas');
        $this->assertEqualsWithDelta(8.0, $lunes['ORD'] ?? 0, 0.01, 'Todas ordinarias diurnas, muy por debajo de las 42h semanales');
    }

    public function test_almuerzo_no_se_descuenta_dos_veces_si_ya_es_turno_partido(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00');

        // Ya viene partido en 2 bloques con el hueco de almuerzo manual (12:00-13:00 fuera de ambos bloques).
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '12:00'], ['13:00', '17:00']]],
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $lunes = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[1]);
        $total = array_sum($lunes);

        $this->assertEqualsWithDelta(8.0, $total, 0.01, 'El turno ya viene partido (4h+4h=8h): el descuento automatico no debe restar una hora adicional');
    }

    public function test_almuerzo_no_se_descuenta_si_el_bloque_no_cubre_la_ventana_completa(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0, '21:00:00', '06:00:00', '12:00:00', '13:00:00');

        // Turno corto que termina antes de que empiece la ventana de almuerzo.
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '11:00']]],
        ]);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $lunes = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[1]);

        $this->assertEqualsWithDelta(3.0, $lunes['ORD'] ?? 0, 0.01, 'El bloque no llega a cubrir la ventana de almuerzo completa: no se descuenta nada');
    }

    public function test_cambio_de_configuracion_a_mitad_de_periodo(): void
    {
        $semana = $this->obtenerSemana('2026-06-01');
        $empleadoId = $this->crearEmpleado();
        $this->crearConfiguracionGlobal($semana[1], 42.0);

        // Lunes a miercoles bajo la jornada base de 42h/semana fijada arriba: 8h x 3 = 24h.
        $this->asignarHorario($empleadoId, [
            ['dia_semana' => 1, 'bloques' => [['08:00', '16:00']]],
            ['dia_semana' => 2, 'bloques' => [['08:00', '16:00']]],
            ['dia_semana' => 3, 'bloques' => [['08:00', '16:00']]],
            ['dia_semana' => 4, 'bloques' => [['08:00', '16:00']]], // jueves 8h, bajo la NUEVA config
        ]);

        // A partir del jueves de esta semana, una reforma reduce la jornada a 30h/semana.
        $this->crearConfiguracionGlobal($semana[4], 30.0);

        $periodoId = $this->crearPeriodo($semana[1], $semana[0]);
        $this->service->calcularPeriodo($empleadoId, $periodoId);

        $jueves = $this->horasPorTipoRecargo($empleadoId, $periodoId, $semana[4]);

        // Acumulado antes del jueves: 24h. Con la nueva jornada de 30h, quedan 6h ordinarias de cupo.
        $this->assertEqualsWithDelta(6.0, $jueves['ORD'] ?? 0, 0.01, 'Le quedaban 6h ordinarias bajo la nueva jornada de 30h vigente desde el jueves');
        $this->assertEqualsWithDelta(2.0, $jueves['HED'] ?? 0, 0.01, 'Las 2h restantes ya superan la nueva jornada semanal vigente ese dia');
    }
}
