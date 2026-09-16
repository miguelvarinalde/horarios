<?php
// app/Services/CalendarioMs365Service.php
namespace App\Services;

use App\Models\ConfiguracionCalendarioMs365Model;
use App\Models\EmpleadoModel;
use App\Models\EventoCalendarioMs365Model;
use App\Models\HorarioBaseModel;
use App\Models\NovedadModel;
use App\Models\UsuarioModel;
use DateTimeImmutable;
use Throwable;

/**
 * Sincroniza horarios/novedades hacia un calendario dedicado ("Horario de
 * trabajo") en el buzon de Outlook de cada empleado, via permiso de
 * aplicacion de Microsoft Graph (GraphCalendarClient). Ver spec
 * docs/superpowers/specs/2026-09-16-sincronizacion-calendario-ms365-design.md.
 *
 * La sincronizacion de horarios trabaja a nivel de VIGENCIA COMPLETA
 * (empleado_id + vigente_desde), no de bloque individual, porque
 * HorarioBaseModel::actualizarVigencia() borra y reinserta todos los
 * bloques de una vigencia en cada edicion — el id de un bloque nunca es
 * estable entre ediciones. La llave estable usada aqui es
 * (empleado_id, vigente_desde, dia_semana, orden dentro del dia).
 *
 * Ningun fallo de Graph en un evento puntual detiene el procesamiento de
 * los demas eventos de la misma llamada, ni revierte nada localmente: la
 * fila de eventos_calendario_ms365 correspondiente queda en estado
 * 'error' con el motivo, visible en /admin/calendario-ms365 para
 * reintentar manualmente.
 */
class CalendarioMs365Service
{
    private ?GraphCalendarClient $clienteInyectado;

    public function __construct(?GraphCalendarClient $cliente = null)
    {
        $this->clienteInyectado = $cliente;
    }

    private function cliente(): GraphCalendarClient
    {
        if ($this->clienteInyectado === null) {
            $config = ConfiguracionCalendarioMs365Model::obtener();
            $this->clienteInyectado = new GraphCalendarClient(
                (string) $config['tenant_id'],
                (string) $config['client_id'],
                (string) $config['client_secret']
            );
        }
        return $this->clienteInyectado;
    }

    public function activo(): bool
    {
        $config = ConfiguracionCalendarioMs365Model::obtener();
        return !empty($config['activo'])
            && !empty($config['tenant_id'])
            && !empty($config['client_id'])
            && !empty($config['client_secret']);
    }

    public function sincronizarVigencia(int $empleadoId, string $vigenteDesde): void
    {
        if (!$this->activo()) {
            return;
        }
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado || empty($empleado['sincronizar_calendario']) || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        $vigencia = HorarioBaseModel::vigencia($empleadoId, $vigenteDesde);
        $diasNuevos = $vigencia['dias'] ?? [];
        $vigenteHasta = $vigencia['vigente_hasta'] ?? null;

        $existentes = EventoCalendarioMs365Model::porVigencia($empleadoId, $vigenteDesde);
        $existentesPorClave = [];
        foreach ($existentes as $ev) {
            $existentesPorClave[$ev['horario_dia_semana'] . ':' . $ev['horario_orden']] = $ev;
        }

        $calendarId = $this->calendarioDe($empleadoId, $email);

        $clavesNuevas = [];
        foreach ($diasNuevos as $diaSemana => $dia) {
            $orden = 1;
            foreach ($dia['bloques'] as $bloque) {
                $clave = $diaSemana . ':' . $orden;
                $clavesNuevas[$clave] = true;
                $existente = $existentesPorClave[$clave] ?? null;
                $payload = $this->payloadBloque((int) $diaSemana, $bloque, $vigenteDesde, $vigenteHasta);

                try {
                    if ($existente && $existente['ms_event_id']) {
                        $this->cliente()->actualizarEvento($email, $calendarId, $existente['ms_event_id'], $payload);
                        $eventId = $existente['ms_event_id'];
                    } else {
                        $eventId = $this->cliente()->crearEvento($email, $calendarId, $payload);
                    }
                    EventoCalendarioMs365Model::guardarHorario([
                        'empleado_id' => $empleadoId,
                        'horario_vigente_desde' => $vigenteDesde,
                        'horario_dia_semana' => $diaSemana,
                        'horario_orden' => $orden,
                        'ms_calendar_id' => $calendarId,
                        'ms_event_id' => $eventId,
                        'estado' => 'sincronizado',
                        'error_mensaje' => null,
                        'sincronizado_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (Throwable $e) {
                    EventoCalendarioMs365Model::guardarHorario([
                        'empleado_id' => $empleadoId,
                        'horario_vigente_desde' => $vigenteDesde,
                        'horario_dia_semana' => $diaSemana,
                        'horario_orden' => $orden,
                        'ms_calendar_id' => $calendarId,
                        'ms_event_id' => $existente['ms_event_id'] ?? null,
                        'estado' => 'error',
                        'error_mensaje' => substr($e->getMessage(), 0, 500),
                        'sincronizado_at' => null,
                    ]);
                }
                $orden++;
            }
        }

        foreach ($existentesPorClave as $clave => $ev) {
            if (isset($clavesNuevas[$clave])) {
                continue;
            }
            if ($ev['ms_event_id']) {
                try {
                    $this->cliente()->eliminarEvento($email, $ev['ms_calendar_id'], $ev['ms_event_id']);
                } catch (Throwable $e) {
                    // Best effort: se elimina igual la fila local para no reintentar por siempre un evento fantasma.
                }
            }
            EventoCalendarioMs365Model::delete((int) $ev['id']);
        }
    }

    public function eliminarVigencia(int $empleadoId, string $vigenteDesde): void
    {
        if (!$this->activo()) {
            return;
        }
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        foreach (EventoCalendarioMs365Model::porVigencia($empleadoId, $vigenteDesde) as $ev) {
            if ($ev['ms_event_id']) {
                try {
                    $this->cliente()->eliminarEvento($email, $ev['ms_calendar_id'], $ev['ms_event_id']);
                } catch (Throwable $e) {
                }
            }
            EventoCalendarioMs365Model::delete((int) $ev['id']);
        }
    }

    public function sincronizarNovedad(int $novedadId): void
    {
        if (!$this->activo()) {
            return;
        }
        $novedad = NovedadModel::conDetalle($novedadId);
        if (!$novedad) {
            return;
        }
        $empleado = EmpleadoModel::find((int) $novedad['empleado_id']);
        if (!$empleado || empty($empleado['sincronizar_calendario']) || empty($empleado['usuario_id'])) {
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        if (!$usuario) {
            return;
        }
        $email = $usuario['email'];

        $calendarId = $this->calendarioDe((int) $empleado['id'], $email);
        $existente = EventoCalendarioMs365Model::porNovedad($novedadId);
        $payload = $this->payloadNovedad($novedad);

        try {
            if ($existente && $existente['ms_event_id']) {
                $this->cliente()->actualizarEvento($email, $calendarId, $existente['ms_event_id'], $payload);
                $eventId = $existente['ms_event_id'];
            } else {
                $eventId = $this->cliente()->crearEvento($email, $calendarId, $payload);
            }
            EventoCalendarioMs365Model::guardarNovedad([
                'empleado_id' => (int) $empleado['id'],
                'novedad_id' => $novedadId,
                'ms_calendar_id' => $calendarId,
                'ms_event_id' => $eventId,
                'estado' => 'sincronizado',
                'error_mensaje' => null,
                'sincronizado_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            EventoCalendarioMs365Model::guardarNovedad([
                'empleado_id' => (int) $empleado['id'],
                'novedad_id' => $novedadId,
                'ms_calendar_id' => $calendarId,
                'ms_event_id' => $existente['ms_event_id'] ?? null,
                'estado' => 'error',
                'error_mensaje' => substr($e->getMessage(), 0, 500),
                'sincronizado_at' => null,
            ]);
        }
    }

    public function eliminarNovedad(int $novedadId): void
    {
        if (!$this->activo()) {
            return;
        }
        $existente = EventoCalendarioMs365Model::porNovedad($novedadId);
        if (!$existente) {
            return;
        }
        $empleado = EmpleadoModel::find((int) $existente['empleado_id']);
        if (!$empleado || empty($empleado['usuario_id'])) {
            EventoCalendarioMs365Model::delete((int) $existente['id']);
            return;
        }
        $usuario = UsuarioModel::find((int) $empleado['usuario_id']);
        $email = $usuario['email'] ?? null;

        if ($email && $existente['ms_event_id']) {
            try {
                $this->cliente()->eliminarEvento($email, $existente['ms_calendar_id'], $existente['ms_event_id']);
            } catch (Throwable $e) {
            }
        }
        EventoCalendarioMs365Model::delete((int) $existente['id']);
    }

    /** @param array{fecha:string, hora_inicio:?string, hora_fin:?string, comentario:?string, tipo_nombre:string} $novedad */
    private function payloadNovedad(array $novedad): array
    {
        $descripcion = $novedad['comentario'] ?: 'Sin comentario adicional.';

        if (empty($novedad['hora_inicio']) || empty($novedad['hora_fin'])) {
            $fin = (new DateTimeImmutable($novedad['fecha']))->modify('+1 day')->format('Y-m-d');
            return [
                'subject' => $novedad['tipo_nombre'],
                'body' => ['contentType' => 'text', 'content' => $descripcion],
                'start' => ['dateTime' => $novedad['fecha'] . 'T00:00:00', 'timeZone' => 'America/Bogota'],
                'end' => ['dateTime' => $fin . 'T00:00:00', 'timeZone' => 'America/Bogota'],
                'isAllDay' => true,
                'isReminderOn' => false,
            ];
        }

        return [
            'subject' => $novedad['tipo_nombre'],
            'body' => ['contentType' => 'text', 'content' => $descripcion],
            'start' => ['dateTime' => $novedad['fecha'] . 'T' . $this->normalizarHora($novedad['hora_inicio']), 'timeZone' => 'America/Bogota'],
            'end' => ['dateTime' => $novedad['fecha'] . 'T' . $this->normalizarHora($novedad['hora_fin']), 'timeZone' => 'America/Bogota'],
            'isReminderOn' => false,
        ];
    }

    /** Reutiliza el calendario ya creado para este empleado (cualquier fila existente); si no hay ninguna, se lo pide a Graph. */
    private function calendarioDe(int $empleadoId, string $email): string
    {
        $existente = EventoCalendarioMs365Model::calendarioIdDeEmpleado($empleadoId);
        return $existente ?? $this->cliente()->obtenerOCrearCalendario($email);
    }

    /** @param array{hora_inicio:string, hora_fin:string} $bloque */
    private function payloadBloque(int $diaSemana, array $bloque, string $vigenteDesde, ?string $vigenteHasta): array
    {
        $diasIngles = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
        $primeraFecha = $this->primeraFechaDesde($vigenteDesde, $diaSemana);

        $rango = $vigenteHasta !== null
            ? ['type' => 'endDate', 'startDate' => $primeraFecha, 'endDate' => $vigenteHasta]
            : ['type' => 'noEnd', 'startDate' => $primeraFecha];

        return [
            'subject' => 'Turno de trabajo',
            'body' => ['contentType' => 'text', 'content' => 'Generado automaticamente por el Sistema de Gestion de Horarios.'],
            'start' => ['dateTime' => $primeraFecha . 'T' . $this->normalizarHora($bloque['hora_inicio']), 'timeZone' => 'America/Bogota'],
            'end' => ['dateTime' => $primeraFecha . 'T' . $this->normalizarHora($bloque['hora_fin']), 'timeZone' => 'America/Bogota'],
            'recurrence' => [
                'pattern' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [$diasIngles[$diaSemana]]],
                'range' => $rango,
            ],
            'isReminderOn' => false,
        ];
    }

    /** Primera fecha en o despues de $desde cuyo dia de la semana (0=domingo..6=sabado) coincide con $diaSemana. */
    private function primeraFechaDesde(string $desde, int $diaSemana): string
    {
        $fecha = new DateTimeImmutable($desde);
        while ((int) $fecha->format('w') !== $diaSemana) {
            $fecha = $fecha->modify('+1 day');
        }
        return $fecha->format('Y-m-d');
    }

    private function normalizarHora(string $hora): string
    {
        return strlen($hora) === 5 ? $hora . ':00' : $hora;
    }
}
