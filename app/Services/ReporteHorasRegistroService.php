<?php

namespace App\Services;

use App\Core\Database;
use App\Models\ConfiguracionGlobalModel;
use App\Models\HorarioBaseModel;
use App\Models\RegistroTiempoModel;
use App\Models\TipoRecargoModel;
use DateTimeImmutable;

/**
 * Informe dia por dia de horas efectivamente trabajadas segun las
 * marcaciones de entrada/salida (registros_tiempo), clasificadas en las
 * mismas categorias legales que usa tipos_recargo (ordinaria/nocturna,
 * extra, dominical/festiva).
 *
 * A proposito NO usa ni modifica CalculoRecargosService ni calculo_detalle:
 * es un informe de auditoria/verificacion aparte, de solo lectura, basado
 * en lo que el empleado realmente marco (no en el horario asignado). El
 * motor de nomina/recargos legal sigue siendo "Horas extra y recargos".
 *
 * La clasificacion "dominical/festivo" aqui es puramente informativa (solo
 * mira si la fecha es domingo o festivo): no aplica la logica de dia
 * compensatorio de Ley 2466 (esa logica pertenece al motor legal y tiene
 * efectos secundarios sobre la tabla dias_compensatorios que este informe
 * de solo lectura no debe disparar).
 *
 * Limitacion deliberada (igual a la de horarios_base_bloques): el
 * emparejamiento entrada/salida se hace dentro del mismo dia calendario.
 * Un turno que cruza medianoche aparecera como incompleto en ambos dias
 * que toca, igual que ya ocurre con los horarios base.
 *
 * Cierre automatico de salidas olvidadas (2026-08-28, a pedido explicito
 * del usuario tras encontrar casos reales en produccion): si el UNICO
 * problema de un dia es que la ULTIMA marcacion es una entrada sin su
 * salida (el patron real de "se le olvido marcar salida"), el dia NO
 * queda "incompleto" — se calcula una hora de cierre y el estado queda
 * 'cerrado_automatico' (distinto de 'completo', para que quede visible en
 * los reportes que la hora de salida es estimada, no una marcacion real).
 * Cualquier OTRA forma de numero impar o de orden invalido (ej. una salida
 * suelta al principio) sigue quedando 'incompleto' sin adivinar nada —
 * ver emparejarDia(). IMPORTANTE: el cierre automatico solo aplica a dias
 * YA TERMINADOS (estrictamente antes de hoy) — la regla original la pidio
 * el usuario para horarios que "no tengan salida a las 11:59 PM", es decir
 * evaluada al final del dia, no a mitad de jornada. Si la ultima entrada
 * suelta es la de HOY, el empleado bien puede seguir trabajando: el dia
 * queda 'en_curso' (ni error, ni una salida estimada) hasta que termine y
 * un reporte generado un dia despues (o mas tarde el mismo dia, ya pasada
 * la medianoche) lo cierre automaticamente si de verdad se le olvido. La
 * regla de cierre (ver calcularSalidaAutomatica()) es, en orden de
 * prioridad:
 *  1. Si el empleado tiene horario_base programado ese dia, la salida es
 *     la hora de fin de su ultimo bloque programado ese dia.
 *  2. Si no hay horario programado y esa entrada es la UNICA marcacion del
 *     dia (jornada continua), se asumen 8h desde la entrada, o 7h si la
 *     entrada fue a las 13:00 o despues.
 *  3. Si no hay horario programado pero SI hay pares entrada/salida ya
 *     completos ese mismo dia (jornada fraccionada), se completa hasta
 *     sumar 7h totales en el dia.
 * Como este informe se calcula siempre al vuelo (nunca escribe en
 * registros_tiempo ni en ninguna otra tabla), esta regla aplica igual de
 * automatico a dias pasados que ya estaban incompletos en produccion,
 * sin necesidad de un backfill aparte.
 *
 * Fusion de marcaciones casi seguidas (2026-08-31, caso real encontrado en
 * produccion: entrada 06:51, salida 06:52, entrada 06:52, salida 15:22).
 * Un par entrada/salida de apenas un par de minutos, pegado sin hueco al
 * siguiente par, no es un turno partido real (nadie almuerza en 1 minuto)
 * sino casi con certeza una marcacion doble accidental (el boton se toco
 * dos veces, o hubo un reintento de ubicacion). Si se dejara como 2
 * segmentos separados, el descuento automatico de almuerzo se saltaria sin
 * razon (la regla de "no restar dos veces" asume que el hueco entre
 * segmentos YA es el almuerzo, pero aqui el hueco es de 0 minutos, no hay
 * ningun almuerzo real ahi). Por eso, antes de decidir el almuerzo o
 * clasificar las horas, fusionarMarcacionesCasiSeguidas() une cualquier
 * par de segmentos consecutivos separados por un hueco de
 * UMBRAL_FUSION_MARCACIONES_MINUTOS minutos o menos en uno solo continuo.
 * La lista de marcaciones que se le muestra al usuario NO se toca (se
 * siguen viendo las 4 marcaciones reales); solo se fusiona el segmento
 * interno usado para calcular horas.
 *
 * Descuento automatico de almuerzo: mismo criterio y misma configuracion
 * (configuracion_global.almuerzo_activo) que CalculoRecargosService, pero
 * aplicado sobre las marcaciones reales en vez del horario asignado. Si el
 * dia tiene un UNICO par entrada/salida (el empleado no marco aparte para
 * almorzar) y ese par cubre por completo la ventana de almuerzo, se resta.
 * Si el empleado SI marco salida/entrada para almorzar (2+ pares), se
 * confia en la marcacion real y no se resta nada de mas.
 *
 * Primera entrada / ultima salida y horas redondeadas (2026-09-02, a
 * pedido del usuario): ademas del desglose exacto, cada dia expone la
 * primera entrada y la ultima salida (el "bookend" del dia, no cada
 * marcacion intermedia) y una version de esas mismas horas y del total
 * redondeadas al bloque de 30 minutos mas cercano (ver redondearHora()).
 * El redondeo se aplica a los segmentos ya definitivos (despues de fusion
 * de marcaciones casi seguidas y descuento de almuerzo) y se suma
 * segmento por segmento, para que un turno partido no cuente como
 * trabajado el hueco entre bloques. Es puramente informativo para este
 * reporte de auditoria: nunca se persiste, nunca alimenta el motor legal
 * ni su clasificacion por tipo de recargo.
 */
class ReporteHorasRegistroService
{
    /**
     * Estados de dia cuyas horas SI cuentan (a diferencia de
     * 'incompleto'/'sin_marcaciones'). 'en_curso' esta incluido porque sus
     * segmentos son SIEMPRE pares entrada/salida ya completados de verdad
     * (nunca incluyen la ultima entrada suelta sin cerrar) — ver
     * emparejarDia().
     */
    private const ESTADOS_CON_HORAS = ['completo', 'cerrado_automatico', 'en_curso'];

    /** Hueco maximo (minutos) entre dos segmentos para considerarlos una marcacion doble accidental y fusionarlos. */
    private const UMBRAL_FUSION_MARCACIONES_MINUTOS = 2;

    /**
     * @return array<int, array{
     *   fecha: string, estado: string, nota: ?string, marcaciones: array,
     *   salida_estimada: ?string, primera_entrada: ?string, ultima_salida: ?string,
     *   primera_entrada_redondeada: ?string, ultima_salida_redondeada: ?string,
     *   horas_totales: float, horas_totales_redondeadas: ?float,
     *   desglose: array<int, array{codigo:string,nombre:string,horas:float}>,
     *   desglose_redondeado: array<int, array{codigo:string,nombre:string,horas:float}>
     * }>
     */
    public function generarInforme(int $empleadoId, string $desde, string $hasta): array
    {
        // Se calcula sobre semanas ISO completas para acumular correctamente
        // el umbral semanal, pero solo se devuelven los dias del rango pedido.
        $inicioSemana = $this->lunesDeLaSemana($desde);
        $finSemana = $this->domingoDeLaSemana($hasta);

        $registros = RegistroTiempoModel::deEmpleadoEnRango($empleadoId, $inicioSemana, $finSemana);
        $porDia = $this->agruparPorDia($registros);
        $festivos = $this->festivosEnRango($inicioSemana, $finSemana);

        $resultado = [];
        $acumuladoSemanal = 0.0;
        // Acumulado semanal aparte para el universo "redondeado" (ver mas
        // abajo): no se mezcla con el exacto porque un dia redondeado puede
        // sumar mas o menos horas que el mismo dia exacto, y el reparto
        // ordinaria/extra de CADA dia debe repartirse contra el acumulado
        // de SU MISMO universo, no contra el del otro.
        $acumuladoSemanalRedondeado = 0.0;
        $semanaActual = null;

        $cursor = new DateTimeImmutable($inicioSemana);
        $limite = new DateTimeImmutable($finSemana);

        while ($cursor <= $limite) {
            $fecha = $cursor->format('Y-m-d');
            $claveSemana = $cursor->format('o-\WW');
            if ($claveSemana !== $semanaActual) {
                $acumuladoSemanal = 0.0;
                $acumuladoSemanalRedondeado = 0.0;
                $semanaActual = $claveSemana;
            }

            $marcacionesDelDia = $porDia[$fecha] ?? [];
            [$segmentos, $estado, $nota] = $this->emparejarDia($empleadoId, $fecha, $marcacionesDelDia);

            if (in_array($estado, self::ESTADOS_CON_HORAS, true)) {
                $segmentos = $this->fusionarMarcacionesCasiSeguidas($segmentos);
            }

            $config = ConfiguracionGlobalModel::vigenteEnFecha($fecha);
            $jornadaSemanal = $config ? (float) $config['jornada_semanal_horas'] : 0.0;
            $inicioNocturno = $config ? $this->normalizar($config['hora_inicio_recargo_nocturno']) : '21:00:00';
            $finNocturno = $config ? $this->normalizar($config['hora_fin_recargo_nocturno']) : '06:00:00';

            // Igual que en el motor legal: solo se aplica cuando el dia
            // tiene un unico segmento (no se marco aparte para almorzar).
            if (in_array($estado, self::ESTADOS_CON_HORAS, true) && count($segmentos) === 1 && $config && !empty($config['almuerzo_activo'])) {
                $segmentos = $this->descontarAlmuerzo($segmentos[0], $config);
            }

            $esDomingoOFestivo = ($cursor->format('w') === '0') || isset($festivos[$fecha]);

            [$horasTotales, $desglosePorTipo] = in_array($estado, self::ESTADOS_CON_HORAS, true)
                ? $this->clasificarSegmentos($segmentos, $fecha, $esDomingoOFestivo, $inicioNocturno, $finNocturno, $jornadaSemanal, $acumuladoSemanal)
                : [0.0, []];

            $salidaEstimada = ($estado === 'cerrado_automatico' && !empty($segmentos)) ? end($segmentos)['hora_fin'] : null;

            // Primera entrada / ultima salida del dia, para las columnas
            // independientes del reporte (a pedido del usuario, 2026-09-02).
            // Son solo el "bookend" del dia (la primera marcacion y la
            // salida final), no cada marcacion intermedia: para el detalle
            // completo (turnos partidos, correcciones) sigue estando la
            // columna "marcaciones". La ultima salida usa la estimada
            // cuando el dia se cerro automatico, para que la columna sea
            // consistente con lo que realmente se conto como salida.
            $primeraEntrada = (!empty($marcacionesDelDia) && $marcacionesDelDia[0]['tipo'] === 'entrada')
                ? substr($marcacionesDelDia[0]['fecha_hora'], 11, 8)
                : null;
            $ultimaSalida = match (true) {
                $estado === 'completo' => substr(end($marcacionesDelDia)['fecha_hora'], 11, 8),
                $estado === 'cerrado_automatico' => $salidaEstimada,
                default => null, // 'en_curso'/'incompleto'/'sin_marcaciones': todavia no hay una salida real ni estimada que mostrar.
            };

            // Horas redondeadas (a pedido del usuario, 2026-09-02, y su
            // desglose por tipo de recargo tambien redondeado, agregado el
            // mismo dia a pedido explicito de "los recargos tambien deben
            // quedar redondeados"): mismo criterio de redondeo (30 min, al
            // mas cercano) aplicado a la hora de inicio/fin de cada
            // segmento ya definitivo (los mismos que se usaron para el
            // calculo exacto, es decir despues de fusionar marcaciones casi
            // seguidas y descontar almuerzo) — asi un turno partido no
            // cuenta como trabajado el hueco entre bloques, y el almuerzo
            // ya descontado se respeta igual en la version redondeada.
            // Los segmentos redondeados se clasifican con la MISMA logica
            // que los exactos (clasificarSegmentos: ventana nocturna,
            // reparto ordinaria/extra) pero contra su PROPIO acumulado
            // semanal (acumuladoSemanalRedondeado), para no mezclar los dos
            // universos. Es informativo/auditoria: no reemplaza el calculo
            // exacto ni alimenta el motor legal.
            $segmentosRedondeados = [];
            foreach ($segmentos as $segmento) {
                $inicioRedondeado = $this->redondearHora($this->normalizar($segmento['hora_inicio']));
                $finRedondeado = $this->redondearHora($this->normalizar($segmento['hora_fin']));
                if ($finRedondeado > $inicioRedondeado) {
                    // Un segmento muy corto puede redondear a duracion cero
                    // (o, en un caso extremo, invertirse) si ambos extremos
                    // caen en el mismo bloque de 30 min o en bloques
                    // adyacentes mal alineados; se omite en vez de sumar
                    // horas negativas o inventadas.
                    $segmentosRedondeados[] = ['hora_inicio' => $inicioRedondeado, 'hora_fin' => $finRedondeado];
                }
            }

            [$horasTotalesRedondeadas, $desglosePorTipoRedondeado] = in_array($estado, self::ESTADOS_CON_HORAS, true)
                ? $this->clasificarSegmentos($segmentosRedondeados, $fecha, $esDomingoOFestivo, $inicioNocturno, $finNocturno, $jornadaSemanal, $acumuladoSemanalRedondeado)
                : [null, []];
            if ($horasTotalesRedondeadas !== null) {
                $horasTotalesRedondeadas = round($horasTotalesRedondeadas, 2);
            }

            $resultado[] = [
                'fecha' => $fecha,
                'es_domingo_o_festivo' => $esDomingoOFestivo,
                'estado' => $estado,
                'nota' => $nota,
                'marcaciones' => $marcacionesDelDia,
                // Solo tiene sentido para 'cerrado_automatico': la hora de
                // salida que se calculo, para mostrarla explicitamente en
                // vez de que RRHH solo vea el total de horas sin saber a
                // que hora se asumio que salio.
                'salida_estimada' => $salidaEstimada,
                'primera_entrada' => $primeraEntrada,
                'ultima_salida' => $ultimaSalida,
                'primera_entrada_redondeada' => $primeraEntrada ? $this->redondearHora($this->normalizar($primeraEntrada)) : null,
                'ultima_salida_redondeada' => $ultimaSalida ? $this->redondearHora($this->normalizar($ultimaSalida)) : null,
                'horas_totales' => round($horasTotales, 2),
                'horas_totales_redondeadas' => $horasTotalesRedondeadas,
                'desglose' => array_values($desglosePorTipo),
                'desglose_redondeado' => array_values($desglosePorTipoRedondeado),
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return array_values(array_filter($resultado, fn ($fila) => $fila['fecha'] >= $desde && $fila['fecha'] <= $hasta));
    }

    /** @return array<string, array> mapa fecha => lista de marcaciones de ese dia, ordenadas */
    private function agruparPorDia(array $registros): array
    {
        $porDia = [];
        foreach ($registros as $r) {
            $fecha = substr($r['fecha_hora'], 0, 10);
            $porDia[$fecha][] = $r;
        }
        return $porDia;
    }

    /**
     * Empareja entrada/salida dentro de un mismo dia. Si el dia no alterna
     * limpiamente entrada,salida,entrada,salida,... lo marca incompleto en
     * vez de intentar adivinar — EXCEPTO cuando el unico problema es que la
     * ULTIMA marcacion es una entrada sin su salida (el patron real de "se
     * le olvido marcar salida"), en cuyo caso se cierra automaticamente
     * (ver calcularSalidaAutomatica() y el docblock de la clase).
     *
     * @return array{0: array, 1: string, 2: ?string}
     */
    private function emparejarDia(int $empleadoId, string $fecha, array $marcacionesDelDia): array
    {
        if (empty($marcacionesDelDia)) {
            return [[], 'sin_marcaciones', null];
        }

        $n = count($marcacionesDelDia);
        $esImpar = $n % 2 !== 0;
        $limiteParesCompletos = $esImpar ? $n - 1 : $n;

        $segmentos = [];
        for ($i = 0; $i < $limiteParesCompletos; $i += 2) {
            $entrada = $marcacionesDelDia[$i];
            $salida = $marcacionesDelDia[$i + 1];

            if ($entrada['tipo'] !== 'entrada' || $salida['tipo'] !== 'salida') {
                return [[], 'incompleto', 'Las marcaciones no alternan entrada/salida correctamente.'];
            }

            $horaEntrada = substr($entrada['fecha_hora'], 11, 8);
            $horaSalida = substr($salida['fecha_hora'], 11, 8);

            if ($horaSalida < $horaEntrada) {
                return [[], 'incompleto', 'Una salida quedo registrada antes que su entrada.'];
            }

            $segmentos[] = ['hora_inicio' => $horaEntrada, 'hora_fin' => $horaSalida];
        }

        if (!$esImpar) {
            return [$segmentos, 'completo', null];
        }

        $ultima = $marcacionesDelDia[$n - 1];
        if ($ultima['tipo'] !== 'entrada') {
            // Numero impar pero la marcacion suelta NO es al final (ej. una
            // salida suelta al principio): no es el patron de "olvido
            // marcar salida", no se adivina nada.
            return [[], 'incompleto', 'Numero impar de marcaciones (falta una entrada o salida).'];
        }

        if ($fecha >= date('Y-m-d')) {
            // El dia todavia no termina (es hoy): la entrada suelta puede
            // ser simplemente que el empleado sigue trabajando, no que se
            // le "olvido" nada. No se calcula ninguna salida estimada; los
            // pares ya completados antes de esta entrada (si los hay, ej.
            // jornada fraccionada ya en curso) SI cuentan sus horas.
            return [$segmentos, 'en_curso', 'El empleado aun no ha marcado salida hoy: la jornada esta en curso.'];
        }

        $horaEntrada = substr($ultima['fecha_hora'], 11, 8);
        [$horaSalidaCalculada, $nota] = $this->calcularSalidaAutomatica($empleadoId, $fecha, $horaEntrada, $segmentos);

        if ($horaSalidaCalculada > $horaEntrada) {
            $segmentos[] = ['hora_inicio' => $horaEntrada, 'hora_fin' => $horaSalidaCalculada];
        }
        // Si la salida calculada no queda despues de la entrada (ya se
        // habian completado 7h+ en pares previos ese dia), no se agrega un
        // segmento con duracion negativa: los pares previos ya cuentan.

        return [$segmentos, 'cerrado_automatico', $nota];
    }

    /**
     * Calcula la hora de cierre para una ultima entrada del dia sin
     * marcar, en orden de prioridad: (1) horario programado ese dia, (2)
     * jornada continua asumida (8h, o 7h si empezo a las 13:00 o despues),
     * (3) jornada fraccionada completada hasta 7h totales del dia. Ver el
     * docblock de la clase para el detalle completo de la regla.
     *
     * @param array $segmentosPrevios pares entrada/salida ya completos ese mismo dia
     * @return array{0: string, 1: string} [hora de cierre calculada (HH:MM:SS), nota para el usuario]
     */
    private function calcularSalidaAutomatica(int $empleadoId, string $fecha, string $horaEntrada, array $segmentosPrevios): array
    {
        $bloquesProgramados = HorarioBaseModel::vigenteEnFecha($empleadoId, $fecha);
        if (!empty($bloquesProgramados)) {
            $horaFinProgramada = $this->normalizar((string) max(array_column($bloquesProgramados, 'hora_fin')));
            return [$horaFinProgramada, 'Cierre automatico: no marco salida. Se tomo la hora de fin de su horario programado ese dia.'];
        }

        if (empty($segmentosPrevios)) {
            $esAntesDeUnaYMedia = $horaEntrada < '13:00:00';
            $horas = $esAntesDeUnaYMedia ? 8.0 : 7.0;
            $salida = $this->sumarHoras($horaEntrada, $horas);
            $horasTexto = $esAntesDeUnaYMedia ? '8 horas' : '7 horas';
            return [$salida, "Cierre automatico: no marco salida. Sin horario programado, jornada continua: se asumieron {$horasTexto} desde la entrada."];
        }

        $horasYaTrabajadas = 0.0;
        foreach ($segmentosPrevios as $seg) {
            $horasYaTrabajadas += $this->horasEntre($seg['hora_inicio'], $seg['hora_fin']);
        }
        $horasRestantes = max(0.0, 7.0 - $horasYaTrabajadas);
        $salida = $this->sumarHoras($horaEntrada, $horasRestantes);
        return [$salida, 'Cierre automatico: no marco salida. Sin horario programado, jornada fraccionada: se completo hasta 7h totales del dia.'];
    }

    /** Suma horas (puede ser fraccionario) a una hora "HH:MM:SS", sin pasar del final del mismo dia calendario. */
    private function sumarHoras(string $hora, float $horasASumar): string
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora));
        $segundos = ($h * 3600 + $m * 60 + $s) + (int) round($horasASumar * 3600);
        $segundos = min($segundos, 23 * 3600 + 59 * 60 + 59);
        return sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }

    /**
     * Redondea una hora "HH:MM:SS" al bloque de minutos mas cercano (30 por
     * defecto, a pedido del usuario). Empate exacto (ej. minuto 15 con
     * bloques de 30) redondea hacia arriba. Solo para las columnas/total
     * "redondeado" del reporte de horas segun registro — nunca se usa para
     * el calculo legal ni se persiste en ningun lado.
     */
    private function redondearHora(string $hora, int $minutos = 30): string
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora));
        $segundos = $h * 3600 + $m * 60 + $s;
        $bloque = $minutos * 60;
        $redondeado = (int) floor(($segundos + $bloque / 2) / $bloque) * $bloque;
        $redondeado = max(0, min($redondeado, 23 * 3600 + 59 * 60 + 59));
        return sprintf('%02d:%02d:%02d', intdiv($redondeado, 3600), intdiv($redondeado % 3600, 60), $redondeado % 60);
    }

    /**
     * Fusiona segmentos consecutivos separados por un hueco casi nulo (ver
     * docblock de la clase): una salida seguida casi de inmediato por una
     * nueva entrada no es un turno partido real, es casi con certeza una
     * marcacion doble accidental. Se fusionan en un solo segmento continuo
     * para que el descuento de almuerzo y la clasificacion de horas no
     * traten ese hueco como si fuera un almuerzo real.
     *
     * @param array<int, array{hora_inicio:string, hora_fin:string}> $segmentos
     * @return array<int, array{hora_inicio:string, hora_fin:string}>
     */
    private function fusionarMarcacionesCasiSeguidas(array $segmentos): array
    {
        if (count($segmentos) < 2) {
            return $segmentos;
        }

        $fusionados = [$segmentos[0]];
        for ($i = 1; $i < count($segmentos); $i++) {
            $ultimo = count($fusionados) - 1;
            $huecoMinutos = max(0.0, $this->horasEntre($fusionados[$ultimo]['hora_fin'], $segmentos[$i]['hora_inicio'])) * 60;

            if ($huecoMinutos <= self::UMBRAL_FUSION_MARCACIONES_MINUTOS) {
                $fusionados[$ultimo]['hora_fin'] = $segmentos[$i]['hora_fin'];
            } else {
                $fusionados[] = $segmentos[$i];
            }
        }

        return $fusionados;
    }

    /**
     * Si el segmento cubre por completo la ventana de almuerzo configurada,
     * la recorta. Si no la cubre completa, no se toca (no se adivina un
     * descuento parcial).
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
            $resultado[] = ['hora_inicio' => $segmento['hora_inicio'], 'hora_fin' => $inicioAlmuerzo];
        }
        if ($fin > $finAlmuerzo) {
            $resultado[] = ['hora_inicio' => $finAlmuerzo, 'hora_fin' => $segmento['hora_fin']];
        }
        return $resultado;
    }

    /**
     * Clasifica una lista de segmentos (ya fusionados y con almuerzo
     * descontado) en horas por tipo de recargo: divide cada uno por ventana
     * nocturna y reparte contra el acumulado semanal recibido (por
     * referencia, se va incrementando). Compartido entre el calculo exacto
     * y el redondeado (ver generarInforme()) — mismo criterio de
     * clasificacion, cada uno con su propio acumulado semanal para no
     * mezclar los dos universos.
     *
     * @param array<int, array{hora_inicio:string, hora_fin:string}> $segmentos
     * @return array{0: float, 1: array<string, array{codigo:string,nombre:string,horas:float}>}
     */
    private function clasificarSegmentos(array $segmentos, string $fecha, bool $esDomingoOFestivo, string $inicioNocturno, string $finNocturno, float $jornadaSemanal, float &$acumuladoSemanal): array
    {
        $horasTotales = 0.0;
        $desglosePorTipo = [];

        foreach ($segmentos as $segmento) {
            $subsegmentos = $this->dividirPorVentanaNocturna(
                $this->normalizar($segmento['hora_inicio']),
                $this->normalizar($segmento['hora_fin']),
                $inicioNocturno,
                $finNocturno
            );

            foreach ($subsegmentos as $sub) {
                $partes = $this->repartirPorAcumuladoSemanal($sub['horas'], $acumuladoSemanal, $jornadaSemanal);
                $acumuladoSemanal += $sub['horas'];

                foreach ($partes as $parte) {
                    if ($parte['horas'] <= 0.0001) {
                        continue;
                    }

                    $horasTotales += $parte['horas'];

                    $tipoRecargo = TipoRecargoModel::buscarPorFlags($fecha, $parte['es_extra'], $sub['es_nocturno'], $esDomingoOFestivo);
                    $clave = $tipoRecargo['codigo'] ?? 'sin_clasificar';
                    if (!isset($desglosePorTipo[$clave])) {
                        $desglosePorTipo[$clave] = [
                            'codigo' => $clave,
                            'nombre' => $tipoRecargo['nombre'] ?? 'Sin clasificar',
                            'horas' => 0.0,
                        ];
                    }
                    $desglosePorTipo[$clave]['horas'] += $parte['horas'];
                }
            }
        }

        return [$horasTotales, $desglosePorTipo];
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
                'es_nocturno' => $this->esHoraNocturna($ini, $inicioNocturno, $finNocturno),
                'horas' => $this->horasEntre($ini, $fin),
            ];
        }

        return $segmentos;
    }

    private function esHoraNocturna(string $t, string $inicioNocturno, string $finNocturno): bool
    {
        if ($inicioNocturno > $finNocturno) {
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
        $iso = (int) $d->format('N');
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
}
