<?php

namespace App\Models;

use App\Core\Model;

class RolModel extends Model
{
    protected static string $table = 'roles';

    public static function permisos(int $rolId): array
    {
        $stmt = static::db()->prepare(
            'SELECT p.* FROM permisos p
             JOIN rol_permisos rp ON rp.permiso_id = p.id
             WHERE rp.rol_id = ?
             ORDER BY p.modulo, p.codigo'
        );
        $stmt->execute([$rolId]);
        return $stmt->fetchAll();
    }

    public static function sincronizarPermisos(int $rolId, array $permisoIds): void
    {
        $db = static::db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM rol_permisos WHERE rol_id = ?')->execute([$rolId]);
            $insert = $db->prepare('INSERT INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)');
            foreach ($permisoIds as $permisoId) {
                $insert->execute([$rolId, (int) $permisoId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
