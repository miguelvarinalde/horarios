<?php

namespace App\Models;

use App\Core\Model;

class PeriodoCalculoModel extends Model
{
    protected static string $table = 'periodos_calculo';

    public static function all(string $orderBy = 'fecha_inicio DESC'): array
    {
        return parent::all($orderBy);
    }

    /** Busca un periodo existente con exactamente ese rango de fechas (para no duplicar el periodo "del mes actual" cada vez que alguien entra al reporte). */
    public static function porFechas(string $fechaInicio, string $fechaFin): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM periodos_calculo WHERE fecha_inicio = ? AND fecha_fin = ? LIMIT 1');
        $stmt->execute([$fechaInicio, $fechaFin]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
