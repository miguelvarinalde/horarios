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

    /**
     * Todos los periodos con la cantidad de filas de calculo_detalle que
     * tiene cada uno, para la pantalla de "eliminar periodos" — asi se ve
     * de un vistazo cuales ya tienen calculo hecho (eliminarlos borra ese
     * calculo en cascada) y cuales estan vacios/sin usar.
     *
     * @return array<int, array{id:int, nombre:string, fecha_inicio:string, fecha_fin:string, estado:string, total_calculos:int}>
     */
    public static function todosConConteoCalculos(string $orderBy = 'pc.fecha_inicio DESC'): array
    {
        $sql = "SELECT pc.*, COUNT(cd.id) AS total_calculos
                FROM periodos_calculo pc
                LEFT JOIN calculo_detalle cd ON cd.periodo_calculo_id = pc.id
                GROUP BY pc.id
                ORDER BY {$orderBy}";
        return static::db()->query($sql)->fetchAll();
    }
}
