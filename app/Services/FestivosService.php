<?php

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Genera el calendario de festivos civiles de Colombia: fechas fijas,
 * el grupo trasladable al lunes siguiente por la Ley 51 de 1983
 * ("Ley Emiliani"), y Jueves/Viernes Santo (no se trasladan).
 *
 * No depende de ext-calendar (no siempre disponible en hosting compartido):
 * la fecha de Pascua se calcula con el algoritmo de Meeus/Jones/Butcher.
 */
class FestivosService
{
    /** Fechas fijas que NUNCA se trasladan de dia. */
    private const FIJOS = [
        ['mes' => 1, 'dia' => 1, 'nombre' => 'Año Nuevo'],
        ['mes' => 5, 'dia' => 1, 'nombre' => 'Dia del Trabajo'],
        ['mes' => 7, 'dia' => 20, 'nombre' => 'Independencia de Colombia'],
        ['mes' => 8, 'dia' => 7, 'nombre' => 'Batalla de Boyaca'],
        ['mes' => 12, 'dia' => 8, 'nombre' => 'Inmaculada Concepcion'],
        ['mes' => 12, 'dia' => 25, 'nombre' => 'Navidad'],
    ];

    /** Fecha base fija que SI se traslada al lunes siguiente (Ley Emiliani). */
    private const LEY_EMILIANI_FIJOS = [
        ['mes' => 1, 'dia' => 6, 'nombre' => 'Reyes Magos'],
        ['mes' => 3, 'dia' => 19, 'nombre' => 'San Jose'],
        ['mes' => 6, 'dia' => 29, 'nombre' => 'San Pedro y San Pablo'],
        ['mes' => 8, 'dia' => 15, 'nombre' => 'Asuncion de la Virgen'],
        ['mes' => 10, 'dia' => 12, 'nombre' => 'Dia de la Raza'],
        ['mes' => 11, 'dia' => 1, 'nombre' => 'Todos los Santos'],
        ['mes' => 11, 'dia' => 11, 'nombre' => 'Independencia de Cartagena'],
    ];

    /** Offset en dias desde el Domingo de Pascua; tambien se trasladan al lunes. */
    private const LEY_EMILIANI_PASCUA = [
        ['offset' => 39, 'nombre' => 'Ascension del Señor'],
        ['offset' => 60, 'nombre' => 'Corpus Christi'],
        ['offset' => 68, 'nombre' => 'Sagrado Corazon de Jesus'],
    ];

    /**
     * Calcula (sin persistir) el calendario de festivos de un año.
     * @return array<int, array{fecha:string, nombre:string, tipo:string}>
     */
    public function calcularAnio(int $anio): array
    {
        $festivos = [];

        foreach (self::FIJOS as $f) {
            $festivos[] = [
                'fecha' => sprintf('%04d-%02d-%02d', $anio, $f['mes'], $f['dia']),
                'nombre' => $f['nombre'],
                'tipo' => 'fijo',
            ];
        }

        foreach (self::LEY_EMILIANI_FIJOS as $f) {
            $base = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $f['mes'], $f['dia']));
            $festivos[] = [
                'fecha' => $this->trasladarALunes($base)->format('Y-m-d'),
                'nombre' => $f['nombre'],
                'tipo' => 'ley_emiliani',
            ];
        }

        $pascua = $this->domingoDePascua($anio);

        $juevesSanto = $pascua->modify('-3 days');
        $viernesSanto = $pascua->modify('-2 days');
        $festivos[] = ['fecha' => $juevesSanto->format('Y-m-d'), 'nombre' => 'Jueves Santo', 'tipo' => 'semana_santa'];
        $festivos[] = ['fecha' => $viernesSanto->format('Y-m-d'), 'nombre' => 'Viernes Santo', 'tipo' => 'semana_santa'];

        foreach (self::LEY_EMILIANI_PASCUA as $f) {
            $base = $pascua->modify("+{$f['offset']} days");
            $festivos[] = [
                'fecha' => $this->trasladarALunes($base)->format('Y-m-d'),
                'nombre' => $f['nombre'],
                'tipo' => 'ley_emiliani',
            ];
        }

        usort($festivos, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return $festivos;
    }

    /**
     * Calcula y persiste el calendario de un año. Los festivos ya definidos
     * manualmente (origen='admin') para ese año se conservan tal cual: si un
     * festivo generado cae en la misma fecha que uno manual, se descarta el
     * generado (INSERT IGNORE) y prevalece el manual.
     */
    public function generarYGuardarAnio(int $anio): int
    {
        $db = Database::connection();

        // Limpia generaciones previas de este año para evitar duplicados si
        // el algoritmo cambiara entre ejecuciones; los festivos con
        // origen='admin' no se tocan.
        $db->prepare("DELETE FROM festivos WHERE anio = ? AND origen = 'generado'")->execute([$anio]);

        $insert = $db->prepare(
            "INSERT IGNORE INTO festivos (fecha, nombre, tipo, anio, origen) VALUES (?, ?, ?, ?, 'generado')"
        );

        $creados = 0;
        foreach ($this->calcularAnio($anio) as $f) {
            $insert->execute([$f['fecha'], $f['nombre'], $f['tipo'], $anio]);
            $creados += $insert->rowCount();
        }

        return $creados;
    }

    public function esFestivo(string $fecha): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM festivos WHERE fecha = ? LIMIT 1');
        $stmt->execute([$fecha]);
        return (bool) $stmt->fetchColumn();
    }

    private function trasladarALunes(DateTimeImmutable $fecha): DateTimeImmutable
    {
        $isoDayOfWeek = (int) $fecha->format('N'); // 1=lunes .. 7=domingo
        if ($isoDayOfWeek === 1) {
            return $fecha;
        }
        $diasParaLunes = (8 - $isoDayOfWeek) % 7;
        return $fecha->modify("+{$diasParaLunes} days");
    }

    /** Algoritmo de Meeus/Jones/Butcher para el Domingo de Pascua (calendario gregoriano). */
    private function domingoDePascua(int $anio): DateTimeImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $numero = $h + $l - 7 * $m + 114;
        $mes = intdiv($numero, 31);
        $dia = ($numero % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
    }
}
