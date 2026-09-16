<?php

namespace App\Models;

use App\Core\Model;

class EventoCalendarioMs365Model extends Model
{
    protected static string $table = 'eventos_calendario_ms365';

    /** @return array<int, array> todas las filas de horario de una vigencia (cualquier dia/orden). */
    public static function porVigencia(int $empleadoId, string $vigenteDesde): array
    {
        $stmt = self::db()->prepare(
            'SELECT * FROM eventos_calendario_ms365 WHERE empleado_id = ? AND horario_vigente_desde = ?'
        );
        $stmt->execute([$empleadoId, $vigenteDesde]);
        return $stmt->fetchAll();
    }

    public static function porNovedad(int $novedadId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM eventos_calendario_ms365 WHERE novedad_id = ?');
        $stmt->execute([$novedadId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array> todas las filas (horario y novedad) de un empleado. */
    public static function porEmpleado(int $empleadoId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM eventos_calendario_ms365 WHERE empleado_id = ?');
        $stmt->execute([$empleadoId]);
        return $stmt->fetchAll();
    }

    /** Reutiliza el calendario "Horario de trabajo" ya creado para este empleado, si existe alguna fila suya. */
    public static function calendarioIdDeEmpleado(int $empleadoId): ?string
    {
        $stmt = self::db()->prepare('SELECT ms_calendar_id FROM eventos_calendario_ms365 WHERE empleado_id = ? LIMIT 1');
        $stmt->execute([$empleadoId]);
        $valor = $stmt->fetchColumn();
        return $valor !== false ? $valor : null;
    }

    /** @return array<int, array> filas en estado 'error', con datos del empleado/novedad para la pantalla de administracion. */
    public static function conError(): array
    {
        return self::db()->query(
            "SELECT ec.*, e.nombre AS empleado_nombre, tn.nombre AS novedad_tipo_nombre, n.fecha AS novedad_fecha
             FROM eventos_calendario_ms365 ec
             JOIN empleados e ON e.id = ec.empleado_id
             LEFT JOIN novedades n ON n.id = ec.novedad_id
             LEFT JOIN tipos_novedad tn ON tn.id = n.tipo_novedad_id
             WHERE ec.estado = 'error'
             ORDER BY ec.updated_at DESC"
        )->fetchAll();
    }

    /**
     * Crea o actualiza (por la llave natural empleado+vigencia+dia+orden) la
     * fila de un bloque de horario. $datos debe traer: empleado_id,
     * horario_vigente_desde, horario_dia_semana, horario_orden,
     * ms_calendar_id, ms_event_id (nullable), estado, error_mensaje
     * (nullable), sincronizado_at (nullable).
     */
    public static function guardarHorario(array $datos): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO eventos_calendario_ms365
                (empleado_id, horario_vigente_desde, horario_dia_semana, horario_orden, ms_calendar_id, ms_event_id, estado, error_mensaje, sincronizado_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ms_calendar_id = VALUES(ms_calendar_id),
                ms_event_id = VALUES(ms_event_id),
                estado = VALUES(estado),
                error_mensaje = VALUES(error_mensaje),
                sincronizado_at = VALUES(sincronizado_at)'
        );
        $stmt->execute([
            $datos['empleado_id'],
            $datos['horario_vigente_desde'],
            $datos['horario_dia_semana'],
            $datos['horario_orden'],
            $datos['ms_calendar_id'],
            $datos['ms_event_id'],
            $datos['estado'],
            $datos['error_mensaje'],
            $datos['sincronizado_at'],
        ]);
    }

    /**
     * Crea o actualiza (por la llave natural novedad_id) la fila de una
     * novedad. Mismas claves que guardarHorario() pero con novedad_id en
     * vez de las 3 columnas de horario.
     */
    public static function guardarNovedad(array $datos): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO eventos_calendario_ms365
                (empleado_id, novedad_id, ms_calendar_id, ms_event_id, estado, error_mensaje, sincronizado_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ms_calendar_id = VALUES(ms_calendar_id),
                ms_event_id = VALUES(ms_event_id),
                estado = VALUES(estado),
                error_mensaje = VALUES(error_mensaje),
                sincronizado_at = VALUES(sincronizado_at)'
        );
        $stmt->execute([
            $datos['empleado_id'],
            $datos['novedad_id'],
            $datos['ms_calendar_id'],
            $datos['ms_event_id'],
            $datos['estado'],
            $datos['error_mensaje'],
            $datos['sincronizado_at'],
        ]);
    }
}
