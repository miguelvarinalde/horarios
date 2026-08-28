<?php

namespace App\Models;

use App\Core\Model;

class NovedadModel extends Model
{
    protected static string $table = 'novedades';

    private const SELECT_BASE = "
        SELECT n.*, e.nombre AS empleado_nombre, e.area_id, tn.nombre AS tipo_nombre, tn.categoria,
               tn.requiere_aprobacion, ua.nombre AS aprobador_nombre
        FROM novedades n
        JOIN empleados e ON e.id = n.empleado_id
        JOIN tipos_novedad tn ON tn.id = n.tipo_novedad_id
        LEFT JOIN usuarios ua ON ua.id = n.aprobado_por
    ";

    public static function todas(): array
    {
        return static::db()->query(self::SELECT_BASE . ' ORDER BY n.fecha DESC, n.id DESC')->fetchAll();
    }

    public static function deEmpleado(int $empleadoId): array
    {
        $stmt = static::db()->prepare(self::SELECT_BASE . ' WHERE n.empleado_id = ? ORDER BY n.fecha DESC, n.id DESC');
        $stmt->execute([$empleadoId]);
        return $stmt->fetchAll();
    }

    /** @param int[] $empleadoIds */
    public static function deEmpleados(array $empleadoIds): array
    {
        if (empty($empleadoIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($empleadoIds), '?'));
        $stmt = static::db()->prepare(self::SELECT_BASE . " WHERE n.empleado_id IN ({$placeholders}) ORDER BY n.fecha DESC, n.id DESC");
        $stmt->execute($empleadoIds);
        return $stmt->fetchAll();
    }

    public static function conDetalle(int $id): ?array
    {
        $stmt = static::db()->prepare(self::SELECT_BASE . ' WHERE n.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Novedades aprobadas que intersectan una fecha (usadas por el motor de calculo). */
    public static function aprobadasEnFecha(int $empleadoId, string $fecha): array
    {
        $stmt = static::db()->prepare(
            "SELECT n.*, tn.categoria, tn.afecta_pago, tn.nombre AS tipo_nombre
             FROM novedades n
             JOIN tipos_novedad tn ON tn.id = n.tipo_novedad_id
             WHERE n.empleado_id = ? AND n.fecha = ? AND n.estado = 'aprobado'"
        );
        $stmt->execute([$empleadoId, $fecha]);
        return $stmt->fetchAll();
    }

    public static function aprobar(int $id, int $usuarioId): void
    {
        $stmt = static::db()->prepare(
            "UPDATE novedades SET estado = 'aprobado', aprobado_por = ?, aprobado_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$usuarioId, $id]);
    }

    public static function rechazar(int $id, int $usuarioId): void
    {
        $stmt = static::db()->prepare(
            "UPDATE novedades SET estado = 'rechazado', aprobado_por = ?, aprobado_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$usuarioId, $id]);
    }
}
