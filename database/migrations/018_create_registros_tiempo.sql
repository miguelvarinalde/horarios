-- Registro de auto-marcacion de entrada/salida con ubicacion. Independiente
-- del motor de calculo (calculo_detalle sigue basandose en horario base +
-- novedades); esta tabla es, por ahora, un registro de auditoria/consulta.
CREATE TABLE IF NOT EXISTS registros_tiempo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    tipo ENUM('entrada', 'salida') NOT NULL,
    fecha_hora DATETIME NOT NULL, -- hora del SERVIDOR (autoritativa; el reloj del cliente no es confiable)
    fecha_hora_cliente DATETIME NULL, -- hora reportada por el navegador, solo como referencia
    latitud DECIMAL(10,7) NULL,
    longitud DECIMAL(10,7) NULL,
    precision_metros DECIMAL(8,2) NULL, -- coords.accuracy del navegador: radio de incertidumbre en metros
    ubicacion_estado ENUM('capturada', 'denegada', 'no_disponible', 'tiempo_agotado', 'no_soportado') NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    comentario VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_registros_tiempo_empleado_fecha (empleado_id, fecha_hora),
    CONSTRAINT fk_registrostiempo_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
