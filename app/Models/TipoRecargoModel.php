<?php

namespace App\Models;

use App\Core\Model;

class TipoRecargoModel extends Model
{
    protected static string $table = 'tipos_recargo';

    /** Vigentes en una fecha especifica (para el motor de calculo). */
    public static function vigentesEnFecha(string $fecha): array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM tipos_recargo
             WHERE vigente_desde <= ?
               AND (vigente_hasta IS NULL OR vigente_hasta >= ?)'
        );
        $stmt->execute([$fecha, $fecha]);
        return $stmt->fetchAll();
    }

    public static function buscarPorFlags(string $fecha, bool $esExtra, bool $esNocturno, bool $esDominicalFestivo): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM tipos_recargo
             WHERE vigente_desde <= ?
               AND (vigente_hasta IS NULL OR vigente_hasta >= ?)
               AND es_hora_extra = ?
               AND es_nocturno = ?
               AND es_dominical_festivo = ?
             ORDER BY vigente_desde DESC
             LIMIT 1'
        );
        $stmt->execute([$fecha, $fecha, (int) $esExtra, (int) $esNocturno, (int) $esDominicalFestivo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
