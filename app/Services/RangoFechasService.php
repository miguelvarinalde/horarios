<?php

namespace App\Services;

/**
 * Valida un rango de fechas recibido por URL antes de usarlo en un informe.
 * Los informes por registro recorren el rango dia por dia con consultas SQL,
 * asi que un rango de siglos (o una fecha mal formada) no debe llegar al
 * motor: una sola peticion saturaria la cuenta compartida del hosting.
 */
class RangoFechasService
{
    public const MAX_DIAS = 366;

    /**
     * @return array{0: string, 1: string}|null [desde, hasta] normalizados Y-m-d, o null si no son validos
     */
    public static function validar(?string $desde, ?string $hasta, int $maxDias = self::MAX_DIAS): ?array
    {
        $d = self::fecha($desde);
        $h = self::fecha($hasta);
        if ($d === null || $h === null || $d > $h) {
            return null;
        }

        if ((int) $d->diff($h)->days + 1 > $maxDias) {
            return null;
        }

        return [$d->format('Y-m-d'), $h->format('Y-m-d')];
    }

    /**
     * Rangos rapidos para programar una vigencia de horario: semana actual,
     * semana siguiente, quincena (2 semanas: la actual y la siguiente),
     * quincena siguiente (las 2 semanas que empiezan el proximo lunes), mes
     * calendario actual y mes siguiente. Las semanas van de lunes a domingo.
     *
     * @return array<int, array{clave: string, etiqueta: string, desde: string, hasta: string}>
     */
    public static function atajosDeVigencia(?\DateTimeImmutable $hoy = null): array
    {
        $hoy = $hoy ?? new \DateTimeImmutable('today');
        $lunes = $hoy->modify('-' . ((int) $hoy->format('N') - 1) . ' days');
        // Desde el dia 1 (no "+1 month" del dia de hoy: el 31 de enero daria marzo).
        $primeroDelMesSiguiente = $hoy->modify('first day of next month');

        return [
            ['clave' => 'semana', 'etiqueta' => 'Semana actual', 'desde' => $lunes->format('Y-m-d'), 'hasta' => $lunes->modify('+6 days')->format('Y-m-d')],
            ['clave' => 'semana_siguiente', 'etiqueta' => 'Semana siguiente', 'desde' => $lunes->modify('+7 days')->format('Y-m-d'), 'hasta' => $lunes->modify('+13 days')->format('Y-m-d')],
            ['clave' => 'quincena', 'etiqueta' => 'Quincena (2 semanas)', 'desde' => $lunes->format('Y-m-d'), 'hasta' => $lunes->modify('+13 days')->format('Y-m-d')],
            ['clave' => 'quincena_siguiente', 'etiqueta' => 'Quincena siguiente', 'desde' => $lunes->modify('+7 days')->format('Y-m-d'), 'hasta' => $lunes->modify('+20 days')->format('Y-m-d')],
            ['clave' => 'mes', 'etiqueta' => 'Mes actual', 'desde' => $hoy->format('Y-m-01'), 'hasta' => $hoy->format('Y-m-t')],
            ['clave' => 'mes_siguiente', 'etiqueta' => 'Mes siguiente', 'desde' => $primeroDelMesSiguiente->format('Y-m-d'), 'hasta' => $primeroDelMesSiguiente->format('Y-m-t')],
        ];
    }

    /**
     * Ventana que puede consultar quien solo ve SUS horas: desde el primer
     * dia del mes anterior hasta el ultimo dia del mes actual.
     *
     * @return array{0: string, 1: string}
     */
    public static function ventanaMesAnteriorYActual(?\DateTimeImmutable $hoy = null): array
    {
        $hoy = $hoy ?? new \DateTimeImmutable('today');
        return [$hoy->modify('first day of last month')->format('Y-m-d'), $hoy->format('Y-m-t')];
    }

    /**
     * Botones de mes para los informes: mes actual (hasta hoy) y mes pasado completo.
     *
     * @return array<int, array{etiqueta: string, desde: string, hasta: string}>
     */
    public static function mesesRapidos(?\DateTimeImmutable $hoy = null): array
    {
        $hoy = $hoy ?? new \DateTimeImmutable('today');
        $anterior = $hoy->modify('first day of last month');

        return [
            ['etiqueta' => 'Mes actual', 'desde' => $hoy->format('Y-m-01'), 'hasta' => $hoy->format('Y-m-d')],
            ['etiqueta' => 'Mes pasado', 'desde' => $anterior->format('Y-m-d'), 'hasta' => $anterior->format('Y-m-t')],
        ];
    }

    /** Fecha estricta Y-m-d (rechaza "2026-02-31", "abc", horas, etc.). */
    public static function fecha(?string $valor): ?\DateTimeImmutable
    {
        if ($valor === null || !preg_match('/^\d{4}-\d{2}-\d{2}\z/', $valor)) {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        $errores = \DateTimeImmutable::getLastErrors();
        if ($fecha === false || ($errores && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }

        return $fecha;
    }
}
