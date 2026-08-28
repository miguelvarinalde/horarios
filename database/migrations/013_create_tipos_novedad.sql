CREATE TABLE IF NOT EXISTS tipos_novedad (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    categoria ENUM('permiso', 'vacaciones', 'incapacidad', 'hora_extra', 'ausencia', 'festivo_trabajado') NOT NULL,
    requiere_aprobacion TINYINT(1) NOT NULL DEFAULT 1,
    afecta_pago TINYINT(1) NOT NULL DEFAULT 1, -- si la novedad debe considerarse en el motor de calculo
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tipos_novedad_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
