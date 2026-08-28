<?php

namespace App\Models;

use App\Core\Model;

class EmpleadoModel extends Model
{
    protected static string $table = 'empleados';

    public static function todosConSupervisor(): array
    {
        return static::db()->query(
            'SELECT e.*, s.nombre AS supervisor_nombre, a.nombre AS area_nombre
             FROM empleados e
             LEFT JOIN empleados s ON s.id = e.supervisor_id
             LEFT JOIN areas a ON a.id = e.area_id
             ORDER BY e.activo DESC, e.nombre'
        )->fetchAll();
    }

    /**
     * Empleados de la misma area (alcance de rol Supervisor y de cualquier
     * pantalla que restrinja por "equipo"). Reemplaza a equipoDe()
     * (supervisor_id), que ya no controla acceso — ver migracion 029.
     */
    public static function delArea(int $areaId): array
    {
        $stmt = static::db()->prepare('SELECT * FROM empleados WHERE area_id = ? ORDER BY nombre');
        $stmt->execute([$areaId]);
        return $stmt->fetchAll();
    }

    public static function porUsuario(int $usuarioId): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM empleados WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function posiblesSupervisores(): array
    {
        return static::db()->query('SELECT id, nombre FROM empleados WHERE activo = 1 ORDER BY nombre')->fetchAll();
    }
}
