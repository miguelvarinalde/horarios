<?php

namespace App\Models;

use App\Core\Database;

/**
 * Areas adicionales que un usuario puede supervisar, ademas de la propia
 * (empleados.area_id) — ver migracion 030_create_supervisor_areas.
 */
class SupervisorAreaModel
{
    private static function db()
    {
        return Database::connection();
    }

    /** @return int[] area_id adicionales asignados a este usuario. */
    public static function areaIdsDe(int $usuarioId): array
    {
        $stmt = self::db()->prepare('SELECT area_id FROM supervisor_areas WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Reemplaza por completo las areas adicionales asignadas a un usuario
     * (borrar + reinsertar), mismo patron que RolModel::sincronizarPermisos
     * y HorarioBaseModel::actualizarVigencia.
     *
     * @param int[] $areaIds
     */
    public static function sincronizar(int $usuarioId, array $areaIds): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM supervisor_areas WHERE usuario_id = ?')->execute([$usuarioId]);

            $insert = $db->prepare('INSERT INTO supervisor_areas (usuario_id, area_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $areaIds)) as $areaId) {
                if ($areaId > 0) {
                    $insert->execute([$usuarioId, $areaId]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
