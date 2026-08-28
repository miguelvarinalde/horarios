<?php

namespace App\Models;

use App\Core\Model;

class AreaModel extends Model
{
    protected static string $table = 'areas';

    public static function activas(): array
    {
        return static::db()->query('SELECT * FROM areas WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }

    public static function all(string $orderBy = 'nombre ASC'): array
    {
        return parent::all($orderBy);
    }
}
