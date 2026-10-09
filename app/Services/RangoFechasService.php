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
