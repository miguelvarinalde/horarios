-- Resultado materializado del motor de calculo. Se regenera por completo
-- (DELETE + INSERT) cada vez que se recalcula un periodo para un empleado;
-- nunca se parchea incrementalmente (ver CalculoRecargosService::calcularPeriodo).
CREATE TABLE IF NOT EXISTS calculo_detalle (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    periodo_calculo_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    tipo_recargo_id INT UNSIGNED NOT NULL,
    horas DECIMAL(6,2) NOT NULL,
    novedad_id INT UNSIGNED NULL, -- trazabilidad: que novedad origino esta franja (si aplica)
    generado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_calculo_detalle_empleado_periodo (empleado_id, periodo_calculo_id),
    KEY idx_calculo_detalle_fecha (fecha),
    CONSTRAINT fk_calculodetalle_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE,
    CONSTRAINT fk_calculodetalle_periodo FOREIGN KEY (periodo_calculo_id) REFERENCES periodos_calculo(id) ON DELETE CASCADE,
    CONSTRAINT fk_calculodetalle_tiporecargo FOREIGN KEY (tipo_recargo_id) REFERENCES tipos_recargo(id),
    CONSTRAINT fk_calculodetalle_novedad FOREIGN KEY (novedad_id) REFERENCES novedades(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
