<?php

namespace App\Services;

use App\Models\PermisoModel;
use App\Models\RolModel;

/**
 * Orquesta la administracion de roles/permisos dinamicos (RBAC), de forma
 * que nuevos roles se puedan crear desde la UI sin tocar codigo.
 */
class RbacService
{
    public function listarRoles(): array
    {
        return RolModel::all('nombre ASC');
    }

    public function listarPermisosAgrupados(): array
    {
        return PermisoModel::agrupadosPorModulo();
    }

    public function crearRol(string $nombre, ?string $descripcion, array $permisoIds): int
    {
        $rolId = RolModel::insert([
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'es_sistema' => 0,
        ]);

        RolModel::sincronizarPermisos($rolId, $permisoIds);

        return $rolId;
    }

    public function actualizarPermisosRol(int $rolId, array $permisoIds): void
    {
        RolModel::sincronizarPermisos($rolId, $permisoIds);
    }

    public function permisosDeRol(int $rolId): array
    {
        return RolModel::permisos($rolId);
    }

    public function eliminarRol(int $rolId): bool
    {
        $rol = RolModel::find($rolId);
        if ($rol === null) {
            return false;
        }
        if ((int) $rol['es_sistema'] === 1) {
            throw new \RuntimeException('No se puede eliminar un rol base del sistema. Puedes editar sus permisos, pero no eliminarlo.');
        }
        return RolModel::delete($rolId);
    }
}
