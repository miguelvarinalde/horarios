-- Periodos no laborables definidos a nivel empresa (ej. cierre colectivo de fin de ano),
-- independientes de los festivos nacionales.
CREATE TABLE IF NOT EXISTS periodos_no_laborables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    aplica_a ENUM('empresa', 'area') NOT NULL DEFAULT 'empresa',
    descripcion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_periodos_no_laborables_fechas (fecha_inicio, fecha_fin),
    CONSTRAINT chk_periodos_no_laborables_fechas CHECK (fecha_fin >= fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
