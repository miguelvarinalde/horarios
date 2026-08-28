CREATE TABLE IF NOT EXISTS novedades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    tipo_novedad_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NULL, -- NULL = novedad de dia completo (ej. vacaciones, incapacidad)
    hora_fin TIME NULL,
    comentario TEXT NULL, -- nota/justificacion opcional (contexto y auditoria)
    estado ENUM('pendiente', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente',
    creado_por INT UNSIGNED NOT NULL,
    aprobado_por INT UNSIGNED NULL,
    aprobado_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_novedades_empleado_fecha (empleado_id, fecha),
    KEY idx_novedades_estado (estado),
    CONSTRAINT fk_novedades_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_novedades_tipo FOREIGN KEY (tipo_novedad_id) REFERENCES tipos_novedad(id),
    CONSTRAINT fk_novedades_creador FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_novedades_aprobador FOREIGN KEY (aprobado_por) REFERENCES usuarios(id),
    CONSTRAINT chk_novedades_horas CHECK (hora_fin IS NULL OR hora_inicio IS NULL OR hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
