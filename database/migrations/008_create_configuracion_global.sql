-- Tabla historizada: cada fila representa la configuracion legal vigente
-- desde una fecha determinada. Nunca se actualiza una fila existente para
-- cambiar un valor; se inserta una fila nueva con una vigente_desde mayor,
-- de modo que periodos pasados siempre se recalculen con la ley que aplicaba
-- en su momento (ver CalculoRecargosService).
CREATE TABLE IF NOT EXISTS configuracion_global (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vigente_desde DATE NOT NULL,
    jornada_semanal_horas DECIMAL(5,2) NOT NULL DEFAULT 42.00,
    hora_inicio_recargo_nocturno TIME NOT NULL DEFAULT '21:00:00',
    hora_fin_recargo_nocturno TIME NOT NULL DEFAULT '06:00:00',
    notas VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_configuracion_global_vigente_desde (vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
