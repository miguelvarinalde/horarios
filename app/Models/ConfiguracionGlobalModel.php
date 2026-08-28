<?php

namespace App\Models;

use App\Core\Model;

class ConfiguracionGlobalModel extends Model
{
    protected static string $table = 'configuracion_global';

    /** Configuracion vigente en una fecha especifica (nunca "la actual"). */
    public static function vigenteEnFecha(string $fecha): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM configuracion_global WHERE vigente_desde <= ? ORDER BY vigente_desde DESC LIMIT 1'
        );
        $stmt->execute([$fecha]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function masReciente(): ?array
    {
        $row = static::db()->query('SELECT * FROM configuracion_global ORDER BY vigente_desde DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public static function historial(): array
    {
        return static::db()->query('SELECT * FROM configuracion_global ORDER BY vigente_desde DESC')->fetchAll();
    }
}
