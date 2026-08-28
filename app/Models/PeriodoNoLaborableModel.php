<?php

namespace App\Models;

use App\Core\Model;

class PeriodoNoLaborableModel extends Model
{
    protected static string $table = 'periodos_no_laborables';

    public static function all(string $orderBy = 'fecha_inicio DESC'): array
    {
        return parent::all($orderBy);
    }

    /** Periodos no laborables que intersectan con [fecha, fecha]. */
    public static function activosEnFecha(string $fecha): array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM periodos_no_laborables WHERE fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $stmt->execute([$fecha, $fecha]);
        return $stmt->fetchAll();
    }
}
