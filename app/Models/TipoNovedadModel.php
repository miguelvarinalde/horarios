<?php

namespace App\Models;

use App\Core\Model;

class TipoNovedadModel extends Model
{
    protected static string $table = 'tipos_novedad';

    /** Valores de la columna `categoria` (enum): ver migraciones 013, 021 y 036. */
    public const CATEGORIAS = ['permiso', 'vacaciones', 'incapacidad', 'hora_extra', 'ausencia', 'festivo_trabajado', 'descanso_compensatorio', 'almuerzo_no_tomado'];

    public static function activos(): array
    {
        return static::db()->query('SELECT * FROM tipos_novedad WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }
}
