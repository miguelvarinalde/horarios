<?php

namespace App\Models;

use App\Core\Model;

class RegistroTiempoModel extends Model
{
    protected static string $table = 'registros_tiempo';

    /** Ultimo registro (entrada o salida) de un empleado, para saber cual es el siguiente tipo esperado. */
    public static function ultimo(int $empleadoId): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM registros_tiempo WHERE empleado_id = ? ORDER BY fecha_hora DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$empleadoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function historialEmpleado(int $empleadoId, int $limite = 20): array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM registros_tiempo WHERE empleado_id = ? ORDER BY fecha_hora DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $empleadoId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Una marcacion puntual con el nombre del empleado, para el formulario de edicion/correccion. */
    public static function conEmpleado(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT rt.*, e.nombre AS empleado_nombre
             FROM registros_tiempo rt
             JOIN empleados e ON e.id = rt.empleado_id
             WHERE rt.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Marcaciones de un empleado en un rango de fechas (inclusive), en orden cronologico. Usado por el informe de horas segun registro. */
    public static function deEmpleadoEnRango(int $empleadoId, string $desde, string $hasta): array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM registros_tiempo
             WHERE empleado_id = ? AND fecha_hora >= ? AND fecha_hora <= ?
             ORDER BY fecha_hora ASC, id ASC'
        );
        $stmt->execute([$empleadoId, $desde . ' 00:00:00', $hasta . ' 23:59:59']);
        return $stmt->fetchAll();
    }

    /**
     * Listado para RRHH/Supervisor/Auditor, con filtros opcionales.
     * @param int[]|null $empleadoIds si se pasa, restringe el alcance (ej. equipo de un supervisor)
     */
    public static function listarConFiltros(?array $empleadoIds, ?string $desde, ?string $hasta, int $limite = 300): array
    {
        $sql = 'SELECT rt.*, e.nombre AS empleado_nombre
                FROM registros_tiempo rt
                JOIN empleados e ON e.id = rt.empleado_id
                WHERE 1=1';
        $params = [];

        if ($empleadoIds !== null) {
            if (empty($empleadoIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($empleadoIds), '?'));
            $sql .= " AND rt.empleado_id IN ({$placeholders})";
            $params = array_merge($params, $empleadoIds);
        }
        if ($desde) {
            $sql .= ' AND rt.fecha_hora >= ?';
            $params[] = $desde . ' 00:00:00';
        }
        if ($hasta) {
            $sql .= ' AND rt.fecha_hora <= ?';
            $params[] = $hasta . ' 23:59:59';
        }

        $sql .= ' ORDER BY rt.fecha_hora DESC, rt.id DESC LIMIT ' . (int) $limite;

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
