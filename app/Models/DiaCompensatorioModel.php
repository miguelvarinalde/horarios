<?php

namespace App\Models;

use App\Core\Model;

class DiaCompensatorioModel extends Model
{
    protected static string $table = 'dias_compensatorios';

    public static function porEmpleadoYFecha(int $empleadoId, string $fecha): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM dias_compensatorios WHERE empleado_id = ? AND fecha_trabajada = ?');
        $stmt->execute([$empleadoId, $fecha]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function crear(int $empleadoId, string $fecha, string $clasificacion, string $tratamiento): int
    {
        return static::insert([
            'empleado_id' => $empleadoId,
            'fecha_trabajada' => $fecha,
            'clasificacion' => $clasificacion,
            'tratamiento' => $tratamiento,
        ]);
    }

    public static function actualizarClasificacion(int $id, string $clasificacion, string $tratamiento): void
    {
        static::update($id, ['clasificacion' => $clasificacion, 'tratamiento' => $tratamiento]);
    }

    /** RRHH cambia la eleccion (solo tiene sentido para casos 'ocasional': recargo <-> descanso_compensatorio). */
    public static function actualizarTratamiento(int $id, string $tratamiento, ?string $comentario): void
    {
        static::update($id, ['tratamiento' => $tratamiento, 'comentario' => $comentario]);
    }

    public static function marcarDescansoTomado(int $id, string $fecha): void
    {
        static::update($id, ['descanso_tomado_fecha' => $fecha]);
    }

    /** Listado para la pantalla de RRHH, con filtros opcionales. @param int[]|null $empleadoIds */
    public static function listarConFiltros(?array $empleadoIds, ?string $desde, ?string $hasta): array
    {
        $sql = 'SELECT dc.*, e.nombre AS empleado_nombre
                FROM dias_compensatorios dc
                JOIN empleados e ON e.id = dc.empleado_id
                WHERE 1=1';
        $params = [];

        if ($empleadoIds !== null) {
            if (empty($empleadoIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($empleadoIds), '?'));
            $sql .= " AND dc.empleado_id IN ({$placeholders})";
            $params = array_merge($params, $empleadoIds);
        }
        if ($desde) {
            $sql .= ' AND dc.fecha_trabajada >= ?';
            $params[] = $desde;
        }
        if ($hasta) {
            $sql .= ' AND dc.fecha_trabajada <= ?';
            $params[] = $hasta;
        }

        $sql .= ' ORDER BY dc.fecha_trabajada DESC';

        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
