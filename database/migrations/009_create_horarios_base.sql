-- Un empleado puede tener varias "vigencias" de horario a lo largo del tiempo
-- (cambios de turno, promociones, etc.). Dentro de cada vigencia, hay una fila
-- por dia de la semana en que el empleado tiene horario asignado.
CREATE TABLE IF NOT EXISTS horarios_base (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    vigente_desde DATE NOT NULL,
    vigente_hasta DATE NULL, -- NULL = vigente indefinidamente hasta que se cree una nueva vigencia
    dia_semana TINYINT UNSIGNED NOT NULL, -- 0=domingo .. 6=sabado (ISO-ish, ver comentario en HorarioModel)
    comentario VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_horarios_base_empleado (empleado_id, vigente_desde),
    CONSTRAINT fk_horariosbase_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT chk_horariosbase_dia_semana CHECK (dia_semana BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
