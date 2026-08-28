<?php

namespace App\Models;

use App\Core\Model;

class PermisoModel extends Model
{
    protected static string $table = 'permisos';

    public static function agrupadosPorModulo(): array
    {
        $rows = static::db()->query('SELECT * FROM permisos ORDER BY modulo, codigo')->fetchAll();
        $agrupado = [];
        foreach ($rows as $row) {
            $agrupado[$row['modulo']][] = $row;
        }
        return $agrupado;
    }
}
