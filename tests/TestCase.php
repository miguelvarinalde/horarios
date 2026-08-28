<?php

namespace Tests;

use App\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base para pruebas de integracion del motor de calculo. Requiere una base
 * de datos MySQL local ya migrada y con los seeds aplicados (ver README):
 *
 *   php scripts/migrate.php
 *   php scripts/seed.php
 *   composer test
 *
 * Cada prueba crea sus propios empleados/horarios/novedades/periodos con
 * datos unicos (documento/email aleatorios) y los elimina en tearDown, para
 * poder correr en paralelo o repetidamente sin dejar residuos ni depender
 * de datos preexistentes (salvo los catalogos de seeds: tipos_recargo,
 * tipos_novedad, configuracion_global, roles).
 */
abstract class TestCase extends BaseTestCase
{
    protected PDO $db;

    /** @var int[] */
    private array $empleadosCreados = [];
    /** @var int[] */
    private array $usuariosCreados = [];
    /** @var int[] */
    private array $periodosCreados = [];
    /** @var int[] */
    private array $configuracionesCreadas = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
    }

    protected function tearDown(): void
    {
        foreach ($this->empleadosCreados as $id) {
            $this->db->prepare('DELETE FROM empleados WHERE id = ?')->execute([$id]);
        }
        foreach ($this->usuariosCreados as $id) {
            $this->db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        }
        foreach ($this->periodosCreados as $id) {
            $this->db->prepare('DELETE FROM periodos_calculo WHERE id = ?')->execute([$id]);
        }
        foreach ($this->configuracionesCreadas as $id) {
            $this->db->prepare('DELETE FROM configuracion_global WHERE id = ?')->execute([$id]);
        }
    }

    protected function crearUsuarioDummy(): int
    {
        $rolId = (int) $this->db->query("SELECT id FROM roles WHERE nombre = 'Empleado'")->fetchColumn();
        $stmt = $this->db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol_id) VALUES (?, ?, ?, ?)');
        $email = 'test_' . bin2hex(random_bytes(6)) . '@example.test';
        $stmt->execute(['Usuario de prueba', $email, password_hash('x', PASSWORD_DEFAULT), $rolId]);
        $id = (int) $this->db->lastInsertId();
        $this->usuariosCreados[] = $id;
        return $id;
    }

    protected function crearEmpleado(): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO empleados (nombre, documento, fecha_ingreso, activo) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['Empleado de prueba', 'TEST-' . bin2hex(random_bytes(6)), '2020-01-01']);
        $id = (int) $this->db->lastInsertId();
        $this->empleadosCreados[] = $id;
        return $id;
    }

    /**
     * @param array<int, array{dia_semana:int, bloques: array<int, array{0:string,1:string}>}> $dias
     */
    protected function asignarHorario(int $empleadoId, array $dias, string $vigenteDesde = '2026-01-01'): void
    {
        $insertDia = $this->db->prepare(
            'INSERT INTO horarios_base (empleado_id, vigente_desde, dia_semana) VALUES (?, ?, ?)'
        );
        $insertBloque = $this->db->prepare(
            'INSERT INTO horarios_base_bloques (horario_base_id, hora_inicio, hora_fin, orden) VALUES (?, ?, ?, ?)'
        );

        foreach ($dias as $dia) {
            $insertDia->execute([$empleadoId, $vigenteDesde, $dia['dia_semana']]);
            $horarioBaseId = (int) $this->db->lastInsertId();
            $orden = 1;
            foreach ($dia['bloques'] as [$inicio, $fin]) {
                $insertBloque->execute([$horarioBaseId, $inicio, $fin, $orden++]);
            }
        }
    }

    /**
     * Igual que asignarHorario, pero para un solo dia_semana con vigencia
     * acotada (vigente_desde y vigente_hasta explicitos) — util para que un
     * horario aplique a UNA fecha especifica y no a todas las que caigan en
     * ese dia de la semana (ej. "solo este domingo", no "todos los domingos").
     *
     * @param array<int, array{0:string,1:string}> $bloques
     */
    protected function asignarHorarioVigenciaAcotada(int $empleadoId, int $diaSemana, string $vigenteDesde, string $vigenteHasta, array $bloques): void
    {
        $insertDia = $this->db->prepare(
            'INSERT INTO horarios_base (empleado_id, vigente_desde, vigente_hasta, dia_semana) VALUES (?, ?, ?, ?)'
        );
        $insertBloque = $this->db->prepare(
            'INSERT INTO horarios_base_bloques (horario_base_id, hora_inicio, hora_fin, orden) VALUES (?, ?, ?, ?)'
        );

        $insertDia->execute([$empleadoId, $vigenteDesde, $vigenteHasta, $diaSemana]);
        $horarioBaseId = (int) $this->db->lastInsertId();
        $orden = 1;
        foreach ($bloques as [$inicio, $fin]) {
            $insertBloque->execute([$horarioBaseId, $inicio, $fin, $orden++]);
        }
    }

    protected function crearNovedadAprobada(int $empleadoId, string $codigoTipo, string $fecha, ?string $horaInicio = null, ?string $horaFin = null): int
    {
        $tipoId = (int) $this->db->query("SELECT id FROM tipos_novedad WHERE codigo = '{$codigoTipo}'")->fetchColumn();
        if (!$tipoId) {
            throw new \RuntimeException("No existe tipo_novedad con codigo {$codigoTipo}. ¿Se aplicaron los seeds?");
        }

        $usuarioId = $this->crearUsuarioDummy();

        $stmt = $this->db->prepare(
            "INSERT INTO novedades (empleado_id, tipo_novedad_id, fecha, hora_inicio, hora_fin, estado, creado_por, aprobado_por, aprobado_at)
             VALUES (?, ?, ?, ?, ?, 'aprobado', ?, ?, NOW())"
        );
        $stmt->execute([$empleadoId, $tipoId, $fecha, $horaInicio, $horaFin, $usuarioId, $usuarioId]);
        return (int) $this->db->lastInsertId();
    }

    protected function crearPeriodo(string $fechaInicio, string $fechaFin): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO periodos_calculo (nombre, fecha_inicio, fecha_fin, estado) VALUES ('Periodo de prueba', ?, ?, 'abierto')"
        );
        $stmt->execute([$fechaInicio, $fechaFin]);
        $id = (int) $this->db->lastInsertId();
        $this->periodosCreados[] = $id;
        return $id;
    }

    protected function crearConfiguracionGlobal(
        string $vigenteDesde,
        float $jornadaSemanal,
        string $inicioNocturno = '21:00:00',
        string $finNocturno = '06:00:00',
        ?string $inicioAlmuerzo = null,
        ?string $finAlmuerzo = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO configuracion_global (vigente_desde, jornada_semanal_horas, hora_inicio_recargo_nocturno, hora_fin_recargo_nocturno, almuerzo_activo, hora_inicio_almuerzo, hora_fin_almuerzo, notas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $vigenteDesde,
            $jornadaSemanal,
            $inicioNocturno,
            $finNocturno,
            $inicioAlmuerzo !== null ? 1 : 0,
            $inicioAlmuerzo,
            $finAlmuerzo,
            'Fila creada por prueba automatizada',
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->configuracionesCreadas[] = $id;
        return $id;
    }

    protected function crearFestivoManual(string $fecha, string $nombre = 'Festivo de prueba'): int
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO festivos (fecha, nombre, tipo, anio, origen) VALUES (?, ?, 'manual', ?, 'admin')"
        );
        $stmt->execute([$fecha, $nombre, (int) substr($fecha, 0, 4)]);
        return (int) $this->db->lastInsertId();
    }

    protected function eliminarFestivo(string $fecha): void
    {
        $this->db->prepare('DELETE FROM festivos WHERE fecha = ?')->execute([$fecha]);
    }

    protected function crearPeriodoNoLaborable(string $fechaInicio, string $fechaFin): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO periodos_no_laborables (nombre, fecha_inicio, fecha_fin, aplica_a) VALUES ('Periodo no laborable de prueba', ?, ?, 'empresa')"
        );
        $stmt->execute([$fechaInicio, $fechaFin]);
        return (int) $this->db->lastInsertId();
    }

    protected function eliminarPeriodoNoLaborable(int $id): void
    {
        $this->db->prepare('DELETE FROM periodos_no_laborables WHERE id = ?')->execute([$id]);
    }

    /** @return string[] fechas (Y-m-d) de todos los domingos del mes calendario indicado (ej. '2026-03'). */
    protected function domingosDelMes(string $anioMes): array
    {
        $cursor = new \DateTimeImmutable($anioMes . '-01');
        $ultimoDia = (int) $cursor->format('t');
        $domingos = [];
        for ($dia = 1; $dia <= $ultimoDia; $dia++) {
            $fecha = $cursor->modify('+' . ($dia - 1) . ' days');
            if ($fecha->format('w') === '0') {
                $domingos[] = $fecha->format('Y-m-d');
            }
        }
        return $domingos;
    }

    protected function obtenerDiaCompensatorio(int $empleadoId, string $fecha): ?array
    {
        return \App\Models\DiaCompensatorioModel::porEmpleadoYFecha($empleadoId, $fecha);
    }

    /**
     * Devuelve las 7 fechas (Y-m-d) de la semana ISO que contiene $fechaAncla,
     * indexadas por dia_semana (0=domingo .. 6=sabado, igual que date('w') y
     * que la columna horarios_base.dia_semana). Se calcula en tiempo de
     * ejecucion (no se hardcodean fechas) para no depender de memorizar que
     * dia de la semana cae una fecha especifica.
     *
     * @return array<int, string>
     */
    protected function obtenerSemana(string $fechaAncla): array
    {
        $cursor = new \DateTimeImmutable($fechaAncla);
        $iso = (int) $cursor->format('N');
        $lunes = $cursor->modify('-' . ($iso - 1) . ' days');

        $dias = [];
        for ($i = 0; $i < 7; $i++) {
            $fecha = $lunes->modify("+{$i} days");
            $dias[(int) $fecha->format('w')] = $fecha->format('Y-m-d');
        }
        return $dias;
    }

    /** @return array<string, float> mapa codigo_tipo_recargo => horas totales, para un empleado/periodo */
    protected function horasPorTipoRecargo(int $empleadoId, int $periodoCalculoId, ?string $fecha = null): array
    {
        $sql = 'SELECT tr.codigo, SUM(cd.horas) AS horas
                FROM calculo_detalle cd
                JOIN tipos_recargo tr ON tr.id = cd.tipo_recargo_id
                WHERE cd.empleado_id = ? AND cd.periodo_calculo_id = ?';
        $params = [$empleadoId, $periodoCalculoId];
        if ($fecha !== null) {
            $sql .= ' AND cd.fecha = ?';
            $params[] = $fecha;
        }
        $sql .= ' GROUP BY tr.codigo';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $resultado = [];
        foreach ($stmt->fetchAll() as $fila) {
            $resultado[$fila['codigo']] = (float) $fila['horas'];
        }
        return $resultado;
    }
}
