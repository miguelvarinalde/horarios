CREATE TABLE IF NOT EXISTS empleados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    supervisor_id INT UNSIGNED NULL,
    nombre VARCHAR(150) NOT NULL,
    documento VARCHAR(40) NOT NULL,
    cargo VARCHAR(120) NULL,
    fecha_ingreso DATE NOT NULL,
    fecha_retiro DATE NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_empleados_documento (documento),
    KEY idx_empleados_supervisor (supervisor_id),
    CONSTRAINT fk_empleados_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_empleados_supervisor FOREIGN KEY (supervisor_id) REFERENCES empleados(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
