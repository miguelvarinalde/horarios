-- Soporte de turnos partidos: un dia de horarios_base puede tener varios bloques
-- (ej. 08:00-12:00 y 14:00-18:00). orden solo controla la presentacion en UI.
CREATE TABLE IF NOT EXISTS horarios_base_bloques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    horario_base_id INT UNSIGNED NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_bloques_horario_base (horario_base_id),
    CONSTRAINT fk_bloques_horario_base FOREIGN KEY (horario_base_id) REFERENCES horarios_base(id) ON DELETE CASCADE,
    CONSTRAINT chk_bloques_horas CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
