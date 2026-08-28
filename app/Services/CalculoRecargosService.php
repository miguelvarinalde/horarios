<?php

namespace App\Services;

use App\Core\Database;
use App\Models\ConfiguracionGlobalModel;
use App\Models\DiaCompensatorioModel;
use App\Models\EmpleadoModel;
use App\Models\HorarioBaseModel;
use App\Models\NovedadModel;
use App\Models\PeriodoCalculoModel;
use App\Models\TipoRecargoModel;
use DateTimeImmutable;
use RuntimeException;

/**
 * Motor de calculo de horas extra y recargos.
 *
 * Enfoque general:
 *  1. Para el periodo solicitado, se expande el rango de trabajo a semanas
 *     ISO completas (lunes a domingo), porque la jornada semanal (art. 161
 *     CST) se acumula por semana calendario, no por periodo de nomina. Un
 *     periodo quincenal que cruza el limite de una semana debe repartir
 *     correctamente cuando se supera el umbral semanal.
 *  2. Por cada dia se construye una lista de "segmentos" de tiempo
 *     trabajado: los bloques del horario base (salvo que el dia este
 *     suspendido por un periodo no laborable o por una novedad de dia
 *     completo tipo permiso/vacaciones/incapacidad/ausencia) mas los
 *     bloques adicionales que vengan de novedades de categoria
 *     'hora_extra' o 'festivo_trabajado'.
 *  3. Cada segmento se divide en sub-segmentos diurno/nocturno segun la
 *     ventana de recargo nocturno vigente ese dia.
 *  4. Los sub-segmentos que vienen del horario base (no de una novedad de
 *     hora_extra, que ya es extra por definicion) se reparten entre horas
 *     ordinarias y horas extra segun el acumulado semanal contra la
 *     jornada_semanal_horas vigente.
 *  5. Cada porcion final (horas, es_extra, es_nocturno, es_dominical_festivo)
 *     se empareja con la fila de tipos_recargo cuyos flags coincidan y
 *     vigente en esa fecha, y se persiste en calculo_detalle.
 *
 * Dia compensatorio (Ley 2466 de 2025, ver dias_compensatorios): cuando un
 * dia domingo/festivo tiene horas realmente trabajadas, se clasifica el mes
 * calendario de esa fecha como 'ocasional' (hasta 2 domingos/festivos
 * trabajados ese mes) o 'habitual' (3 o mas). En 'ocasional' el trabajador
 * elige entre recargo o descanso compensatorio (por defecto: recargo, hasta
 * que RRHH registre lo contrario); en 'habitual' tiene derecho a ambos y no
 * es opcional. Si el tratamiento elegido es 'descanso_compensatorio' puro,
 * esas horas se calculan como si NO fueran dominicales/festivas (se pagan
 * como ordinarias/extra normales), porque la compensacion es el dia libre,
 * no el recargo.
 *
 * Limitacion conocida: horarios_base_bloques no permite turnos que crucen
 * la medianoche (hora_fin > hora_inicio dentro del mismo dia). Un turno
 * nocturno que cruza medianoche debe registrarse como dos bloques (uno en
 * el dia que empieza y otro en el dia siguiente).
 *
 * Descuento automatico de almuerzo (configuracion_global.almuerzo_activo):
 * si un dia del horario base tiene UN SOLO bloque continuo (no turno
 * partido) y ese bloque cubre por completo la ventana de almuerzo
 * configurada, se resta esa ventana antes de dividir por recargo
 * nocturno/acumulado semanal. Si el dia YA viene partido en 2+ bloques, se
 * asume que el hueco entre ellos ya es el almuerzo (mecanismo existente,
 * sin cambios) y NO se aplica el descuento automatico, para no restarlo
 * dos veces.
 */
class CalculoRecargosService
{
    private const CATEGORIAS_SUSPENSIVAS = ['permiso', 'vacaciones', 'incapacidad', 'ausencia', 'descanso_compensatorio'];
    private const CATEGORIAS_ADITIVAS = ['hora_extra', 'festivo_trabajado'];

    /** Memoiza, dentro de una misma llamada, cuantos domingos/festivos trabajo un empleado en un mes (empleadoId:AAAA-MM => int). */
    private array $cacheDiasTrabajadosPorMes = [];

    /** Ejecuta calcularPeriodo() para todos los empleados activos. Usado por el boton "Calcular" del reporte. */
    /**
     * @param int[]|null $empleadoIds si se pasa, solo recalcula esos empleados (ej. el area
     *        de un Supervisor); null = todos los empleados activos (comportamiento original).
     */
    public function calcularPeriodoTodosLosEmpleados(int $periodoCalculoId, ?array $empleadoIds = null): void
    {
        foreach (EmpleadoModel::where('activo', 1) as $empleado) {
            if ($empleadoIds !== null && !in_array((int) $empleado['id'], $empleadoIds, true)) {
                continue;
            }
            $this->calcularPeriodo((int) $empleado['id'], $periodoCalculoId);
        }
    }

    public function calcularPeriodo(int $empleadoId, int $periodoCalculoId): array
    {
        // El cache de dias-trabajados-por-mes solo es valido dentro de esta
        // llamada: entre llamadas puede haber cambiado el horario/novedades
        // subyacentes (ej. se agrego un domingo trabajado adicional), y con
        // eso la clasificacion ocasional/habitual de un mes ya calculado.
        $this->cacheDiasTrabajadosPorMes = [];

        $periodo = PeriodoCalculoModel::find($periodoCalculoId);
        if (!$periodo) {
            throw new RuntimeException("Periodo de calculo {$periodoCalculoId} no encontrado.");
        }

        $inicioSemana = $this->lunesDeLaSemana($periodo['fecha_inicio']);
        $finSemana = $this->domingoDeLaSemana($periodo['fecha_fin']);

        $festivos = $this->festivosEnRango($inicioSemana, $finSemana);
        $periodosNoLaborables = $this->periodosNoLaborablesEnRango($inicioSemana, $finSemana);

        $filas = [];
        $acumuladoSemanal = 0.0;
        $semanaActual = null;

        $cursor = new DateTimeImmutable($inicioSemana);
        $limite = new DateTimeImmutable($finSemana);

        while ($cursor <= $limite) {
            $fecha = $cursor->format('Y-m-d');
            $claveSemana = $cursor->format('o-\WW');
            if ($claveSemana !== $semanaActual) {
                $acumuladoSemanal = 0.0;
                $semanaActual = $claveSemana;
            }

            $config = ConfiguracionGlobalModel::vigenteEnFecha($fecha);
            if (!$config) {
                throw new RuntimeException("No hay configuracion_global vigente para la fecha {$fecha}.");
            }
            $jornadaSemanal = (float) $config['jornada_semanal_horas'];
            $inicioNocturno = $this->normalizar($config['hora_inicio_recargo_nocturno']);
            $finNocturno = $this->normalizar($config['hora_fin_recargo_nocturno']);

            $esDomingoOFestivo = ($cursor->format('w') === '0') || isset($festivos[$fecha]);
            $esPeriodoNoLaborable = isset($periodosNoLaborables[$fecha]);

            $novedadesDelDia = NovedadModel::aprobadasEnFecha($empleadoId, $fecha);
            $segmentos = $this->segmentosDelDia($empleadoId, $fecha, $novedadesDelDia, $esPeriodoNoLaborable, $config);

            // Si se trabajo un domingo/festivo, resuelve si ese dia se paga
            // como recargo, se compensa con descanso, o ambos (Ley 2466).
            $esDomingoOFestivoEfectivo = $esDomingoOFestivo;
            if ($esDomingoOFestivo && !empty($segmentos)) {
                $diaCompensatorio = $this->resolverDiaCompensatorio($empleadoId, $fecha);
                if ($diaCompensatorio['tratamiento'] === 'descanso_compensatorio') {
                    // Solo descanso, sin recargo: se paga como si fuera un dia ordinario.
                    $esDomingoOFestivoEfectivo = false;
                }
            }

            foreach ($segmentos as $segmento) {
                $subsegmentos = $this->dividirPorVentanaNocturna(
                    $this->normalizar($segmento['hora_inicio']),
                    $this->normalizar($segmento['hora_fin']),
                    $inicioNocturno,
                    $finNocturno
                );

                foreach ($subsegmentos as $sub) {
                    if ($segmento['extra_forzado']) {
                        $partes = [['horas' => $sub['horas'], 'es_extra' => true]];
                    } else {
                        $partes = $this->repartirPorAcumuladoSemanal($sub['horas'], $acumuladoSemanal, $jornadaSemanal);
                        $acumuladoSemanal += $sub['horas'];
                    }

                    foreach ($partes as $parte) {
                        if ($parte['horas'] <= 0.0001) {
                            continue;
                        }

                        $tipoRecargo = TipoRecargoModel::buscarPorFlags($fecha, $parte['es_extra'], $sub['es_nocturno'], $esDomingoOFestivoEfectivo);
                        if (!$tipoRecargo) {
                            throw new RuntimeException(sprintf(
                                'No hay tipo_recargo configurado (extra=%s, nocturno=%s, dom_festivo=%s) vigente en %s.',
                                $parte['es_extra'] ? '1' : '0',
                                $sub['es_nocturno'] ? '1' : '0',
                                $esDomingoOFestivoEfectivo ? '1' : '0',
                                $fecha
                            ));
                        }

                        if ($fecha >= $periodo['fecha_inicio'] && $fecha <= $periodo['fecha_fin']) {
                            $filas[] = [
                                'empleado_id' => $empleadoId,
                                'periodo_calculo_id' => $periodoCalculoId,
                                'fecha' => $fecha,
                                'tipo_recargo_id' => (int) $tipoRecargo['id'],
                                'horas' => round($parte['horas'], 2),
                                'novedad_id' => $segmento['novedad_id'],
                            ];
                        }
                    }
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        $this->reemplazarDetalle($empleadoId, $periodoCalculoId, $filas);

        return $filas;
    }

    /**
     * Construye los segmentos de tiempo trabajado de un dia: bloques del
     * horario base (salvo suspension) + bloques adicionales de novedades.
     *
     * @param array $config fila vigente de configuracion_global (para el
     *              descuento automatico de almuerzo); se puede omitir (p.ej.
     *              desde clasificarMesDominicalFestivo, que solo necesita
     *              saber si hubo trabajo ese dia, no las horas netas).
     */
    private function segmentosDelDia(int $empleadoId, string $fecha, array $novedadesDelDia, bool $esPeriodoNoLaborable, array $config = []): array
    {
        $segmentos = [];

        $suspendido = $esPeriodoNoLaborable || $this->tieneNovedadSuspensivaDeDiaCompleto($novedadesDelDia);

        if (!$suspendido) {
            $bloquesHorario = HorarioBaseModel::vigenteEnFecha($empleadoId, $fecha);
            $segmentosHorario = [];
            foreach ($bloquesHorario as $bloque) {
                $segmentosHorario[] = [
                    'hora_inicio' => $bloque['hora_inicio'],
                    'hora_fin' => $bloque['hora_fin'],
                    'novedad_id' => null,
                    'extra_forzado' => false,
                ];
            }

            // Solo se aplica a un dia SIN turno partido (un unico bloque):
            // si ya viene partido en 2+ bloques, el hueco entre ellos ya
            // cumple el rol de almuerzo y no hay que restar de nuevo.
            if (count($segmentosHorario) === 1 && !empty($config['almuerzo_activo'])) {
                $segmentosHorario = $this->descontarAlmuerzo($segmentosHorario[0], $config);
            }

            $segmentos = array_merge($segmentos, $segmentosHorario);
        }

        foreach ($novedadesDelDia as $novedad) {
            if (in_array($novedad['categoria'], self::CATEGORIAS_ADITIVAS, true)
                && !empty($novedad['hora_inicio']) && !empty($novedad['hora_fin'])) {
                $segmentos[] = [
                    'hora_inicio' => $novedad['hora_inicio'],
                    'hora_fin' => $novedad['hora_fin'],
                    'novedad_id' => (int) $novedad['id'],
                    'extra_forzado' => $novedad['categoria'] === 'hora_extra',
                ];
            }
        }

        usort($segmentos, fn ($a, $b) => strcmp($a['hora_inicio'], $b['hora_inicio']));

        return $segmentos;
    }

    /**
     * Si el segmento cubre por completo la ventana de almuerzo configurada,
     * la recorta (puede dejar 0, 1 o 2 sub-segmentos: antes/despues del
     * almuerzo). Si el segmento no cubre la ventana completa (empieza
     * despues o termina antes), no se toca — no se adivina un descuento
     * parcial.
     *
     * @return array<int, array{hora_inicio:string, hora_fin:string, novedad_id:null, extra_forzado:false}>
     */
    private function descontarAlmuerzo(array $segmento, array $config): array
    {
        $inicioAlmuerzo = $this->normalizar($config['hora_inicio_almuerzo']);
        $finAlmuerzo = $this->normalizar($config['hora_fin_almuerzo']);
        $inicio = $this->normalizar($segmento['hora_inicio']);
        $fin = $this->normalizar($segmento['hora_fin']);

        if ($inicio > $inicioAlmuerzo || $fin < $finAlmuerzo) {
            return [$segmento];
        }

        $resultado = [];
        if ($inicioAlmuerzo > $inicio) {
            $resultado[] = ['hora_inicio' => $segmento['hora_inicio'], 'hora_fin' => $inicioAlmuerzo, 'novedad_id' => null, 'extra_forzado' => false];
        }
        if ($fin > $finAlmuerzo) {
            $resultado[] = ['hora_inicio' => $finAlmuerzo, 'hora_fin' => $segmento['hora_fin'], 'novedad_id' => null, 'extra_forzado' => false];
        }
        return $resultado;
    }

    private function tieneNovedadSuspensivaDeDiaCompleto(array $novedadesDelDia): bool
    {
        foreach ($novedadesDelDia as $n) {
            if (in_array($n['categoria'], self::CATEGORIAS_SUSPENSIVAS, true) && empty($n['hora_inicio'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Obtiene (o crea/actualiza) el registro de dias_compensatorios para un
     * domingo/festivo efectivamente trabajado, reclasificando el mes si es
     * necesario (ver docblock de la clase).
     */
    private function resolverDiaCompensatorio(int $empleadoId, string $fecha): array
    {
        $clasificacion = $this->clasificarMesDominicalFestivo($empleadoId, $fecha) >= 3 ? 'habitual' : 'ocasional';

        $existente = DiaCompensatorioModel::porEmpleadoYFecha($empleadoId, $fecha);

        if ($existente) {
            if ($existente['clasificacion'] !== $clasificacion) {
                // Si paso a habitual, el tratamiento deja de ser opcional: ambos.
                $nuevoTratamiento = $clasificacion === 'habitual' ? 'ambos' : $existente['tratamiento'];
                DiaCompensatorioModel::actualizarClasificacion($existente['id'], $clasificacion, $nuevoTratamiento);
                $existente['clasificacion'] = $clasificacion;
                $existente['tratamiento'] = $nuevoTratamiento;
            }
            return $existente;
        }

        // Por defecto: 'recargo' (no cambia el comportamiento previo) hasta
        // que RRHH registre explicitamente la eleccion de descanso; si el
        // mes ya es habitual desde el primer calculo, aplica 'ambos' de una vez.
        $tratamientoInicial = $clasificacion === 'habitual' ? 'ambos' : 'recargo';
        $id = DiaCompensatorioModel::crear($empleadoId, $fecha, $clasificacion, $tratamientoInicial);

        return [
            'id' => $id,
            'empleado_id' => $empleadoId,
            'fecha_trabajada' => $fecha,
            'clasificacion' => $clasificacion,
            'tratamiento' => $tratamientoInicial,
        ];
    }

    /** Cuenta cuantos domingos/festivos distintos trabajo el empleado en el mes calendario que contiene $fecha. */
    private function clasificarMesDominicalFestivo(int $empleadoId, string $fecha): int
    {
        $clave = $empleadoId . ':' . substr($fecha, 0, 7);
        if (isset($this->cacheDiasTrabajadosPorMes[$clave])) {
            return $this->cacheDiasTrabajadosPorMes[$clave];
        }

        $inicioMes = date('Y-m-01', strtotime($fecha));
        $finMes = date('Y-m-t', strtotime($fecha));
        $festivosDelMes = $this->festivosEnRango($inicioMes, $finMes);
        $periodosNoLaborablesDelMes = $this->periodosNoLaborablesEnRango($inicioMes, $finMes);

        $diasTrabajados = 0;
        $cursor = new DateTimeImmutable($inicioMes);
        $limite = new DateTimeImmutable($finMes);
        while ($cursor <= $limite) {
            $f = $cursor->format('Y-m-d');
            $esDomingoOFestivo = ($cursor->format('w') === '0') || isset($festivosDelMes[$f]);
            if ($esDomingoOFestivo) {
                $novedadesDelDia = NovedadModel::aprobadasEnFecha($empleadoId, $f);
                $segmentos = $this->segmentosDelDia($empleadoId, $f, $novedadesDelDia, isset($periodosNoLaborablesDelMes[$f]));
                if (!empty($segmentos)) {
                    $diasTrabajados++;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        $this->cacheDiasTrabajadosPorMes[$clave] = $diasTrabajados;
        return $diasTrabajados;
    }

    /** Divide [horaInicio, horaFin) en sub-segmentos diurno/nocturno. */
    private function dividirPorVentanaNocturna(string $horaInicio, string $horaFin, string $inicioNocturno, string $finNocturno): array
    {
        $puntos = [$horaInicio, $horaFin];
        foreach ([$inicioNocturno, $finNocturno] as $p) {
            if ($p > $horaInicio && $p < $horaFin) {
                $puntos[] = $p;
            }
        }
        sort($puntos);
        $puntos = array_values(array_unique($puntos));

        $segmentos = [];
        for ($i = 0; $i < count($puntos) - 1; $i++) {
            $ini = $puntos[$i];
            $fin = $puntos[$i + 1];
            $segmentos[] = [
                'hora_inicio' => $ini,
                'hora_fin' => $fin,
                'es_nocturno' => $this->esHoraNocturna($ini, $inicioNocturno, $finNocturno),
                'horas' => $this->horasEntre($ini, $fin),
            ];
        }

        return $segmentos;
    }

    private function esHoraNocturna(string $t, string $inicioNocturno, string $finNocturno): bool
    {
        if ($inicioNocturno > $finNocturno) {
            // La ventana cruza medianoche (caso normal: 21:00 - 06:00)
            return $t >= $inicioNocturno || $t < $finNocturno;
        }
        return $t >= $inicioNocturno && $t < $finNocturno;
    }

    /** @return array<int, array{horas:float, es_extra:bool}> */
    private function repartirPorAcumuladoSemanal(float $horas, float $acumulado, float $jornadaSemanal): array
    {
        $capacidadRestante = max(0.0, $jornadaSemanal - $acumulado);

        if ($capacidadRestante >= $horas) {
            return [['horas' => $horas, 'es_extra' => false]];
        }

        $partes = [];
        if ($capacidadRestante > 0.0001) {
            $partes[] = ['horas' => $capacidadRestante, 'es_extra' => false];
        }
        $partes[] = ['horas' => $horas - $capacidadRestante, 'es_extra' => true];

        return $partes;
    }

    private function normalizar(string $hora): string
    {
        return strlen($hora) === 5 ? $hora . ':00' : $hora;
    }

    private function horasEntre(string $ini, string $fin): float
    {
        [$h1, $m1, $s1] = array_map('intval', explode(':', $ini));
        [$h2, $m2, $s2] = array_map('intval', explode(':', $fin));
        $segundos = ($h2 * 3600 + $m2 * 60 + $s2) - ($h1 * 3600 + $m1 * 60 + $s1);
        return $segundos / 3600;
    }

    private function lunesDeLaSemana(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        $iso = (int) $d->format('N'); // 1=lunes .. 7=domingo
        return $d->modify('-' . ($iso - 1) . ' days')->format('Y-m-d');
    }

    private function domingoDeLaSemana(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        $iso = (int) $d->format('N');
        return $d->modify('+' . (7 - $iso) . ' days')->format('Y-m-d');
    }

    /** @return array<string, true> mapa fecha => true */
    private function festivosEnRango(string $desde, string $hasta): array
    {
        $stmt = Database::connection()->prepare('SELECT fecha FROM festivos WHERE fecha BETWEEN ? AND ?');
        $stmt->execute([$desde, $hasta]);
        return array_fill_keys($stmt->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    /** @return array<string, true> mapa fecha => true para cada dia cubierto por algun periodo no laborable */
    private function periodosNoLaborablesEnRango(string $desde, string $hasta): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT fecha_inicio, fecha_fin FROM periodos_no_laborables WHERE fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $stmt->execute([$hasta, $desde]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $periodo) {
            $cursor = new DateTimeImmutable(max($periodo['fecha_inicio'], $desde));
            $limite = new DateTimeImmutable(min($periodo['fecha_fin'], $hasta));
            while ($cursor <= $limite) {
                $mapa[$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $mapa;
    }

    private function reemplazarDetalle(int $empleadoId, int $periodoCalculoId, array $filas): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM calculo_detalle WHERE empleado_id = ? AND periodo_calculo_id = ?')
                ->execute([$empleadoId, $periodoCalculoId]);

            if (!empty($filas)) {
                $insert = $db->prepare(
                    'INSERT INTO calculo_detalle (empleado_id, periodo_calculo_id, fecha, tipo_recargo_id, horas, novedad_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($filas as $fila) {
                    $insert->execute([
                        $fila['empleado_id'],
                        $fila['periodo_calculo_id'],
                        $fila['fecha'],
                        $fila['tipo_recargo_id'],
                        $fila['horas'],
                        $fila['novedad_id'],
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
