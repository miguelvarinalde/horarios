<?php

namespace App\Models;

use App\Core\Model;

class PeriodoNoLaborableModel extends Model
{
    protected static string $table = 'periodos_no_laborables';

    public static function all(string $orderBy = 'fecha_inicio DESC'): array
    {
        return parent::all($orderBy);
    }

    /** @return int[] area_id a los que aplica el periodo; vacio = toda la empresa */
    public static function areaIds(int $periodoId): array
    {
        $stmt = static::db()->prepare('SELECT area_id FROM periodos_no_laborables_areas WHERE periodo_id = ? ORDER BY area_id');
        $stmt->execute([$periodoId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Reemplaza las areas del periodo. Lista vacia = toda la empresa.
     * Mantiene aplica_a coherente ('area' si hay areas, 'empresa' si no).
     *
     * @param int[] $areaIds
     */
    public static function sincronizarAreas(int $periodoId, array $areaIds): void
    {
        $areaIds = array_values(array_unique(array_map('intval', $areaIds)));
        $db = static::db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM periodos_no_laborables_areas WHERE periodo_id = ?')->execute([$periodoId]);
            $insert = $db->prepare('INSERT INTO periodos_no_laborables_areas (periodo_id, area_id) VALUES (?, ?)');
            foreach ($areaIds as $areaId) {
                $insert->execute([$periodoId, $areaId]);
            }
            $db->prepare('UPDATE periodos_no_laborables SET aplica_a = ? WHERE id = ?')
                ->execute([$areaIds ? 'area' : 'empresa', $periodoId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cada periodo con la lista de nombres de area a las que aplica
     * (`areas` vacio = toda la empresa). Si se pasa un rango, solo los que
     * lo intersectan.
     *
     * @return array<int, array>
     */
    public static function conAreas(?string $desde = null, ?string $hasta = null): array
    {
        if ($desde !== null && $hasta !== null) {
            $stmt = static::db()->prepare('SELECT * FROM periodos_no_laborables WHERE fecha_inicio <= ? AND fecha_fin >= ? ORDER BY fecha_inicio DESC');
            $stmt->execute([$hasta, $desde]);
            $periodos = $stmt->fetchAll();
        } else {
            $periodos = static::all();
        }

        $nombres = [];
        $filas = static::db()->query(
            'SELECT pa.periodo_id, a.nombre FROM periodos_no_laborables_areas pa JOIN areas a ON a.id = pa.area_id ORDER BY a.nombre'
        )->fetchAll();
        foreach ($filas as $f) {
            $nombres[(int) $f['periodo_id']][] = $f['nombre'];
        }

        foreach ($periodos as &$p) {
            $p['areas'] = $nombres[(int) $p['id']] ?? [];
        }
        unset($p);

        return $periodos;
    }

    /** Periodos no laborables que intersectan con [fecha, fecha]. */
    public static function activosEnFecha(string $fecha): array
    {
        $stmt = static::db()->prepare(
            'SELECT * FROM periodos_no_laborables WHERE fecha_inicio <= ? AND fecha_fin >= ?'
        );
        $stmt->execute([$fecha, $fecha]);
        return $stmt->fetchAll();
    }
}
