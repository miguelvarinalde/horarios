-- database/migrations/033_create_eventos_calendario_ms365.sql
-- Mapeo entre un horario/novedad local y su evento en Microsoft Graph.
-- La sincronizacion de horarios trabaja a nivel de VIGENCIA COMPLETA
-- (empleado_id + horario_vigente_desde), no de bloque individual: el id de
-- un bloque en horarios_base_bloques NUNCA sobrevive una edicion
-- (HorarioBaseModel::actualizarVigencia() borra y reinserta todo), asi que
-- la llave estable es (empleado_id, horario_vigente_desde, dia_semana,
-- orden). Las novedades si son estables (novedades.id nunca cambia), por
-- eso su llave es simplemente novedad_id.
CREATE TABLE IF NOT EXISTS eventos_calendario_ms365 (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    novedad_id INT UNSIGNED NULL,
    horario_vigente_desde DATE NULL,
    horario_dia_semana TINYINT UNSIGNED NULL,
    horario_orden TINYINT UNSIGNED NULL,
    ms_calendar_id VARCHAR(150) NOT NULL,
    ms_event_id VARCHAR(150) NULL,
    estado ENUM('sincronizado', 'error') NOT NULL DEFAULT 'error',
    error_mensaje VARCHAR(500) NULL,
    sincronizado_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evento_novedad (novedad_id),
    UNIQUE KEY uq_evento_horario (empleado_id, horario_vigente_desde, horario_dia_semana, horario_orden),
    CONSTRAINT chk_evento_calendario_tipo CHECK (
        (novedad_id IS NOT NULL AND horario_vigente_desde IS NULL)
        OR
        (novedad_id IS NULL AND horario_vigente_desde IS NOT NULL)
    ),
    CONSTRAINT fk_eventoscalendario_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_eventoscalendario_novedad FOREIGN KEY (novedad_id) REFERENCES novedades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
