<?php

namespace App\Services;

use App\Core\Auth;
use App\Models\EmpleadoModel;
use App\Models\SupervisorAreaModel;

/**
 * Resuelve, en un solo lugar, que areas/empleados puede ver el usuario en
 * sesion — antes esta logica estaba duplicada (con pequenas variaciones)
 * en 6 controladores distintos. El alcance es:
 *
 *  - Con el permiso equipos.ver_todas (tipicamente RRHH/Administrador/
 *    Auditor): TODAS las areas, sin restriccion.
 *  - Sin ese permiso: su propia area (empleados.area_id) MAS cualquier
 *    area adicional asignada via supervisor_areas (2026-09-05, a pedido
 *    del usuario: "es posible que un supervisor revise varios
 *    departamentos" — antes el alcance era binario, propia area o todas).
 *  - Si no tiene area propia ni areas adicionales: solo se ve a si mismo.
 *
 * Los metodos que devuelven `?array` usan `null` para "sin restriccion"
 * (ver todas), igual que el patron ya usado en los controladores
 * (empleadoIdsPermitidos), para que un `null` explicito nunca se confunda
 * con "ninguno" (array vacio).
 */
class AlcanceAreasService
{
    /** @return int[]|null area_id visibles para el usuario en sesion; null = TODAS. */
    public static function areaIdsPermitidos(): ?array
    {
        if (Auth::veTodasLasAreas()) {
            return null;
        }

        $usuarioId = (int) Auth::id();
        $areaIds = SupervisorAreaModel::areaIdsDe($usuarioId);

        $empleadoPropio = EmpleadoModel::porUsuario($usuarioId);
        if ($empleadoPropio && $empleadoPropio['area_id']) {
            $areaIds[] = (int) $empleadoPropio['area_id'];
        }

        return array_values(array_unique($areaIds));
    }

    /** @return int[]|null empleado_id visibles para el usuario en sesion; null = TODOS (sin restriccion). */
    public static function empleadoIdsPermitidos(): ?array
    {
        $areaIds = self::areaIdsPermitidos();
        if ($areaIds === null) {
            return null;
        }

        $empleados = empty($areaIds) ? [] : EmpleadoModel::deLasAreas($areaIds);
        $ids = array_map(fn ($e) => (int) $e['id'], $empleados);

        // Siempre se ve a si mismo, tenga o no area asignada — nunca debe
        // quedar fuera de su propio alcance.
        $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
        if ($empleadoPropio && !in_array((int) $empleadoPropio['id'], $ids, true)) {
            $ids[] = (int) $empleadoPropio['id'];
        }

        return $ids;
    }

    /**
     * Filas completas de empleado en el alcance (para selects/listados).
     * A diferencia de empleadoIdsPermitidos(), siempre devuelve un array
     * concreto (nunca null): "ve todas" se resuelve aqui mismo a
     * EmpleadoModel::todosConSupervisor().
     */
    public static function empleadosPermitidos(): array
    {
        if (Auth::veTodasLasAreas()) {
            return EmpleadoModel::todosConSupervisor();
        }

        $areaIds = self::areaIdsPermitidos();
        $empleados = empty($areaIds) ? [] : EmpleadoModel::deLasAreas($areaIds);

        $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
        $idsYaIncluidos = array_map(fn ($e) => (int) $e['id'], $empleados);
        if ($empleadoPropio && !in_array((int) $empleadoPropio['id'], $idsYaIncluidos, true)) {
            $empleados[] = $empleadoPropio;
        }

        return $empleados;
    }
}
