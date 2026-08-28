<?php

namespace App\Models;

use App\Core\Model;

class FestivoModel extends Model
{
    protected static string $table = 'festivos';

    public static function porAnio(int $anio): array
    {
        $stmt = static::db()->prepare('SELECT * FROM festivos WHERE anio = ? ORDER BY fecha');
        $stmt->execute([$anio]);
        return $stmt->fetchAll();
    }

    public static function crearManual(string $fecha, string $nombre): int
    {
        $anio = (int) substr($fecha, 0, 4);
        return static::insert([
            'fecha' => $fecha,
            'nombre' => $nombre,
            'tipo' => 'manual',
            'anio' => $anio,
            'origen' => 'admin',
        ]);
    }
}
