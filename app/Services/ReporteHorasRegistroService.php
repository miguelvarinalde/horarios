<?php

namespace App\Services;

use App\Core\Database;
use App\Models\ConfiguracionGlobalModel;
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
 * Descuento automatico de almuerzo: mismo criterio y misma configuracion
 * (configuracion_global.almuerzo_activo) que CalculoRecargosService, pero
 * aplicado sobre las marcaciones reales en vez del horario asignado. Si el
 * dia tiene un UNICO par entrada/salida (el empleado no marco aparte para
 * almorzar) y ese par cubre por completo la ventana de almuerzo, se resta.
 * Si el empleado SI marco salida/entrada para almorzar (2+ pares), se
 * confia en la marcacion real y no se resta nada de mas.
 */
class ReporteHorasRegistroService
{
    /**
     * @return array<int, array{
     *   fecha: string, estado: string, nota: ?string, marcaciones: array,
     *   horas_totales: float, desglose: array<int, array{codigo:string,nombre:string,horas:float}>
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

            $marcacionesDelDia = $porDia[$fecha] ?? [];
            [$segmentos, $estado, $nota] = $this->emparejarDia($marcacionesDelDia);

            $config = ConfiguracionGlobalModel::vigenteEnFecha($fecha);
            $jornadaSemanal = $config ? (float) $config['jornada_semanal_horas'] : 0.0;
            $inicioNocturno = $config ? $this->normalizar($config['hora_inicio_recargo_nocturno']) : '21:00:00';
            $finNocturno = $config ? $this->normalizar($config['hora_fin_recargo_nocturno']) : '06:00:00';

            // Igual que en el motor legal: solo se aplica cuando el dia
            // tiene un unico segmento (no se marco aparte para almorzar).
            if ($estado === 'completo' && count($segmentos) === 1 && $config && !empty($config['almuerzo_activo'])) {
                $segmentos = $this->descontarAlmuerzo($segmentos[0], $config);
            }

            $esDomingoOFestivo = ($cursor->format('w') === '0') || isset($festivos[$fecha]);

            $horasTotales = 0.0;
            $desglosePorTipo = [];

            if ($estado === 'completo') {
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
            }

            $resultado[] = [
                'fecha' => $fecha,
                'es_domingo_o_festivo' => $esDomingoOFestivo,
                'estado' => $estado,
                'nota' => $nota,
                'marcaciones' => $marcacionesDelDia,
                'horas_totales' => round($horasTotales, 2),
                'desglose' => array_values($desglosePorTipo),
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
     * vez de intentar adivinar.
     *
     * @return array{0: array, 1: string, 2: ?string}
     */
    private function emparejarDia(array $marcacionesDelDia): array
    {
        if (empty($marcacionesDelDia)) {
            return [[], 'sin_marcaciones', null];
        }

        if (count($marcacionesDelDia) % 2 !== 0) {
            return [[], 'incompleto', 'Numero impar de marcaciones (falta una entrada o salida).'];
        }

        $segmentos = [];
        for ($i = 0; $i < count($marcacionesDelDia); $i += 2) {
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

        return [$segmentos, 'completo', null];
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
