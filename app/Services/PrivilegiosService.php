<?php

namespace App\Services;

use App\Core\Database;

/**
 * Regla unica para evitar la escalada de privilegios entre cuentas:
 * un usuario solo puede gestionar (resetear la contrasena, cambiar el
 * correo, dar de baja) a otro usuario, o asignar un rol, cuando TODOS los
 * permisos de ese usuario/rol ya los tiene el mismo. Asi RRHH puede
 * administrar Empleados, Supervisores y Auditores, pero no al
 * Administrador (que tiene permisos admin.* que RRHH no tiene), y un rol
 * personalizado nuevo hereda la regla sin tocar codigo.
 *
 * Los metodos reciben los permisos del actor explicitamente (en vez de
 * leerlos de la sesion) para poder probarlos sin sesion; Auth ofrece los
 * atajos que usan los controladores.
 */
class PrivilegiosService
{
    /** @return string[] codigos de permiso del rol */
    public static function permisosDeRol(int $rolId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.codigo
             FROM rol_permisos rp
             JOIN permisos p ON p.id = rp.permiso_id
             WHERE rp.rol_id = ?'
        );
        $stmt->execute([$rolId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return string[] codigos de permiso que tiene el usuario por su rol */
    public static function permisosDeUsuario(int $usuarioId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.codigo
             FROM usuarios u
             JOIN rol_permisos rp ON rp.rol_id = u.rol_id
             JOIN permisos p ON p.id = rp.permiso_id
             WHERE u.id = ?'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** true si todo lo de $requeridos esta contenido en $propios. */
    public static function incluye(array $propios, array $requeridos): bool
    {
        return array_diff($requeridos, $propios) === [];
    }

    /** @param string[] $permisosActor */
    public static function puedeGestionarUsuario(array $permisosActor, int $usuarioObjetivoId): bool
    {
        return self::incluye($permisosActor, self::permisosDeUsuario($usuarioObjetivoId));
    }

    /** El rol debe existir y no dar mas permisos de los que tiene el actor. @param string[] $permisosActor */
    public static function puedeAsignarRol(array $permisosActor, int $rolId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM roles WHERE id = ?');
        $stmt->execute([$rolId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        return self::incluye($permisosActor, self::permisosDeRol($rolId));
    }

    /**
     * Roles que el actor puede asignar (para llenar el select del formulario).
     *
     * @param string[] $permisosActor
     * @return array<int, array> filas de `roles`
     */
    public static function rolesAsignables(array $permisosActor): array
    {
        $db = Database::connection();
        $porRol = [];
        foreach ($db->query('SELECT rp.rol_id, p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id') as $fila) {
            $porRol[(int) $fila['rol_id']][] = $fila['codigo'];
        }

        $asignables = [];
        foreach ($db->query('SELECT * FROM roles ORDER BY nombre ASC')->fetchAll() as $rol) {
            if (self::incluye($permisosActor, $porRol[(int) $rol['id']] ?? [])) {
                $asignables[] = $rol;
            }
        }
        return $asignables;
    }

    /**
     * ids de usuario que el actor puede gestionar, en una sola consulta
     * (para ocultar botones en listados sin una consulta por fila).
     *
     * @param string[] $permisosActor
     * @return int[]
     */
    public static function idsUsuariosGestionables(array $permisosActor): array
    {
        $db = Database::connection();
        $porUsuario = [];
        $filas = $db->query(
            'SELECT u.id, p.codigo
             FROM usuarios u
             JOIN rol_permisos rp ON rp.rol_id = u.rol_id
             JOIN permisos p ON p.id = rp.permiso_id'
        );
        foreach ($filas as $fila) {
            $porUsuario[(int) $fila['id']][] = $fila['codigo'];
        }

        $ids = [];
        foreach ($db->query('SELECT id FROM usuarios')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (self::incluye($permisosActor, $porUsuario[(int) $id] ?? [])) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }
}
