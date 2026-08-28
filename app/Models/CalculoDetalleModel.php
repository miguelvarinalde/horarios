<?php

namespace App\Models;

use App\Core\Database;

class CalculoDetalleModel
{
    /**
     * @param int[]|null $empleadoIds si se pasa, restringe el resumen a esos empleados
     *        (ej. el equipo de un Supervisor); null = sin restriccion (RRHH/Administrador/Auditor).
     * @return array<int, array{empleado_id:int, empleado_nombre:string, codigo:string, horas:float}>
     */
    public static function resumenPorPeriodo(int $periodoCalculoId, ?array $empleadoIds = null): array
    {
        $sql = 'SELECT cd.empleado_id, e.nombre AS empleado_nombre, tr.codigo, SUM(cd.horas) AS horas
                FROM calculo_detalle cd
                JOIN empleados e ON e.id = cd.empleado_id
                JOIN tipos_recargo tr ON tr.id = cd.tipo_recargo_id
                WHERE cd.periodo_calculo_id = ?';
        $params = [$periodoCalculoId];

        if ($empleadoIds !== null) {
            if (empty($empleadoIds)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($empleadoIds), '?'));
            $sql .= " AND cd.empleado_id IN ({$placeholders})";
            $params = array_merge($params, $empleadoIds);
        }

        $sql .= ' GROUP BY cd.empleado_id, e.nombre, tr.codigo ORDER BY e.nombre, tr.codigo';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array{fecha:string, codigo:string, horas:float, comentario:?string}> */
    public static function detalleEmpleadoPeriodo(int $empleadoId, int $periodoCalculoId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cd.fecha, tr.codigo, tr.nombre AS tipo_nombre, cd.horas, n.comentario
             FROM calculo_detalle cd
             JOIN tipos_recargo tr ON tr.id = cd.tipo_recargo_id
             LEFT JOIN novedades n ON n.id = cd.novedad_id
             WHERE cd.empleado_id = ? AND cd.periodo_calculo_id = ?
             ORDER BY cd.fecha, tr.codigo'
        );
        $stmt->execute([$empleadoId, $periodoCalculoId]);
        return $stmt->fetchAll();
    }
}
