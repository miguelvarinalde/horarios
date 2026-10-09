-- Alcance por area de los periodos no laborables. Hasta ahora todo periodo
-- aplicaba a TODA la empresa: la columna periodos_no_laborables.aplica_a
-- ('empresa'/'area') existia pero el motor la ignoraba y no habia forma de
-- decir a que area aplicaba (una vacacion colectiva de una sola area
-- suspendia el horario de todos los empleados).
--
-- Regla: un periodo SIN filas aqui sigue siendo de toda la empresa (asi los
-- periodos ya registrados no cambian de comportamiento); un periodo CON
-- filas aplica solo a los empleados de esas areas. Un empleado sin area
-- solo se ve afectado por periodos de toda la empresa.
CREATE TABLE IF NOT EXISTS periodos_no_laborables_areas (
    periodo_id INT UNSIGNED NOT NULL,
    area_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (periodo_id, area_id),
    KEY idx_pnl_areas_area (area_id),
    CONSTRAINT fk_pnlareas_periodo FOREIGN KEY (periodo_id) REFERENCES periodos_no_laborables(id) ON DELETE CASCADE,
    CONSTRAINT fk_pnlareas_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
