CREATE TABLE IF NOT EXISTS periodos_calculo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL, -- ej. "Quincena 1 - Julio 2026"
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    estado ENUM('abierto', 'cerrado') NOT NULL DEFAULT 'abierto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_periodos_calculo_fechas (fecha_inicio, fecha_fin),
    CONSTRAINT chk_periodos_calculo_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
