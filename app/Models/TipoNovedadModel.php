<?php

namespace App\Models;

use App\Core\Model;

class TipoNovedadModel extends Model
{
    protected static string $table = 'tipos_novedad';

    public static function activos(): array
    {
        return static::db()->query('SELECT * FROM tipos_novedad WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }
}
