<?php

namespace App\Models;

use App\Core\Database;

/**
 * dia_semana: 0=domingo, 1=lunes, 2=martes, 3=miercoles, 4=jueves, 5=viernes, 6=sabado
 * (compatible con PHP `date('w', ...)`).
 *
 * Una "vigencia" logica agrupa todas las filas horarios_base de un empleado
 * que comparten el mismo vigente_desde (una fila por cada dia de la semana
 * en que trabaja). Cada fila puede tener 1+ bloques (turnos partidos).
 */
class HorarioBaseModel
{
    private static function db()
    {
        return Database::connection();
    }

    /**
     * Crea una nueva vigencia de horario para un empleado.
     * $dias = [ dia_semana => [ ['hora_inicio' => 'HH:MM', 'hora_fin' => 'HH:MM'], ... ], ... ]
     */
    public static function crearVigencia(int $empleadoId, string $vigenteDesde, ?string $vigenteHasta, ?string $comentario, array $dias): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $insertDia = $db->prepare(
                'INSERT INTO horarios_base (empleado_id, vigente_desde, vigente_hasta, dia_semana, comentario) VALUES (?, ?, ?, ?, ?)'
            );
            $insertBloque = $db->prepare(
                'INSERT INTO horarios_base_bloques (horario_base_id, hora_inicio, hora_fin, orden) VALUES (?, ?, ?, ?)'
            );

            foreach ($dias as $diaSemana => $bloques) {
                if (empty($bloques)) {
                    continue;
                }
                $insertDia->execute([$empleadoId, $vigenteDesde, $vigenteHasta, $diaSemana, $comentario]);
                $horarioBaseId = (int) $db->lastInsertId();

                $orden = 1;
                foreach ($bloques as $bloque) {
                    if (empty($bloque['hora_inicio']) || empty($bloque['hora_fin'])) {
                        continue;
                    }
                    $insertBloque->execute([$horarioBaseId, $bloque['hora_inicio'], $bloque['hora_fin'], $orden]);
                    $orden++;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Lista las vigencias de un empleado, con sus dias y bloques agrupados. */
    public static function porEmpleado(int $empleadoId): array
    {
        $stmt = self::db()->prepare(
            'SELECT hb.*, b.id AS bloque_id, b.hora_inicio, b.hora_fin, b.orden
             FROM horarios_base hb
             LEFT JOIN horarios_base_bloques b ON b.horario_base_id = hb.id
             WHERE hb.empleado_id = ?
             ORDER BY hb.vigente_desde DESC, hb.dia_semana ASC, b.orden ASC'
        );
        $stmt->execute([$empleadoId]);
        $rows = $stmt->fetchAll();

        $vigencias = [];
        foreach ($rows as $row) {
            $clave = $row['vigente_desde'];
            $vigencias[$clave]['vigente_desde'] ??= $row['vigente_desde'];
            $vigencias[$clave]['vigente_hasta'] ??= $row['vigente_hasta'];
            $vigencias[$clave]['dias'][$row['dia_semana']]['comentario'] ??= $row['comentario'];
            if ($row['bloque_id'] !== null) {
                $vigencias[$clave]['dias'][$row['dia_semana']]['bloques'][] = [
                    'hora_inicio' => $row['hora_inicio'],
                    'hora_fin' => $row['hora_fin'],
                ];
            }
        }

        return $vigencias;
    }

    /**
     * Horario base vigente para un empleado en una fecha especifica, con sus
     * bloques (turnos partidos). Usado por CalculoRecargosService.
     */
    public static function vigenteEnFecha(int $empleadoId, string $fecha): array
    {
        $diaSemana = (int) date('w', strtotime($fecha));

        $stmt = self::db()->prepare(
            'SELECT hb.id, b.hora_inicio, b.hora_fin
             FROM horarios_base hb
             JOIN horarios_base_bloques b ON b.horario_base_id = hb.id
             WHERE hb.empleado_id = ?
               AND hb.dia_semana = ?
               AND hb.vigente_desde <= ?
               AND (hb.vigente_hasta IS NULL OR hb.vigente_hasta >= ?)
             ORDER BY hb.vigente_desde DESC, b.orden ASC'
        );
        $stmt->execute([$empleadoId, $diaSemana, $fecha, $fecha]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return [];
        }

        // Si hay mas de una vigencia superpuesta (no deberia pasar si se
        // gestionan bien, pero por seguridad se toma la mas reciente).
        $horarioBaseIdMasReciente = $rows[0]['id'];
        return array_values(array_filter($rows, fn ($r) => $r['id'] === $horarioBaseIdMasReciente));
    }

    public static function eliminarVigencia(int $empleadoId, string $vigenteDesde): void
    {
        $stmt = self::db()->prepare('DELETE FROM horarios_base WHERE empleado_id = ? AND vigente_desde = ?');
        $stmt->execute([$empleadoId, $vigenteDesde]);
    }

    /** Una sola vigencia (con sus dias/bloques), o null si no existe. Para precargar el formulario de edicion. */
    public static function vigencia(int $empleadoId, string $vigenteDesde): ?array
    {
        $todas = self::porEmpleado($empleadoId);
        return $todas[$vigenteDesde] ?? null;
    }

    /**
     * Reemplaza por completo los dias/bloques de una vigencia existente
     * (identificada por empleado_id + vigente_desde, que no cambia) y
     * actualiza vigente_hasta/comentario. Antes, la unica forma de "editar"
     * un horario era eliminar la vigencia entera y volver a crearla; esto
     * hace lo mismo por dentro (borrar + reinsertar, mismo patron que
     * RolModel::sincronizarPermisos) pero como una sola accion atomica.
     *
     * $dias = [ dia_semana => [ ['hora_inicio' => 'HH:MM', 'hora_fin' => 'HH:MM'], ... ], ... ]
     */
    public static function actualizarVigencia(int $empleadoId, string $vigenteDesde, ?string $vigenteHasta, ?string $comentario, array $dias): void
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM horarios_base WHERE empleado_id = ? AND vigente_desde = ?')
                ->execute([$empleadoId, $vigenteDesde]);

            $insertDia = $db->prepare(
                'INSERT INTO horarios_base (empleado_id, vigente_desde, vigente_hasta, dia_semana, comentario) VALUES (?, ?, ?, ?, ?)'
            );
            $insertBloque = $db->prepare(
                'INSERT INTO horarios_base_bloques (horario_base_id, hora_inicio, hora_fin, orden) VALUES (?, ?, ?, ?)'
            );

            foreach ($dias as $diaSemana => $bloques) {
                if (empty($bloques)) {
                    continue;
                }
                $insertDia->execute([$empleadoId, $vigenteDesde, $vigenteHasta, $diaSemana, $comentario]);
                $horarioBaseId = (int) $db->lastInsertId();

                $orden = 1;
                foreach ($bloques as $bloque) {
                    if (empty($bloque['hora_inicio']) || empty($bloque['hora_fin'])) {
                        continue;
                    }
                    $insertBloque->execute([$horarioBaseId, $bloque['hora_inicio'], $bloque['hora_fin'], $orden]);
                    $orden++;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
