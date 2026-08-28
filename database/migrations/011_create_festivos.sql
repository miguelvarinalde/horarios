CREATE TABLE IF NOT EXISTS festivos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    tipo ENUM('fijo', 'ley_emiliani', 'semana_santa', 'manual') NOT NULL,
    anio SMALLINT UNSIGNED NOT NULL,
    origen ENUM('generado', 'admin') NOT NULL DEFAULT 'generado',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_festivos_fecha (fecha),
    KEY idx_festivos_anio (anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
