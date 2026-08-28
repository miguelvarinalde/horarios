-- Rastrea, por cada domingo/festivo efectivamente trabajado por un empleado,
-- la clasificacion legal (Ley 2466 de 2025) y el tratamiento aplicable:
--
--   clasificacion: se cuenta por MES CALENDARIO (no por periodo de nomina).
--     'ocasional'  = hasta 2 domingos/festivos trabajados ese mes
--     'habitual'   = 3 o mas domingos/festivos trabajados ese mes
--
--   tratamiento:
--     'recargo'                -> solo se paga el recargo dominical/festivo (por defecto)
--     'descanso_compensatorio' -> solo se otorga un dia de descanso pagado, SIN recargo
--                                  (unicamente valido si clasificacion = 'ocasional': es
--                                  una eleccion del trabajador)
--     'ambos'                  -> recargo Y descanso compensatorio (obligatorio si
--                                  clasificacion = 'habitual': no es opcional)
--
-- Se recalcula automaticamente cada vez que se ejecuta el motor de calculo
-- (CalculoRecargosService), igual que el resto del sistema: nunca se parchea
-- a mano, salvo el campo `tratamiento` para los casos ocasionales, que RRHH
-- puede cambiar explicitamente ANTES de recalcular el periodo.
CREATE TABLE IF NOT EXISTS dias_compensatorios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT UNSIGNED NOT NULL,
    fecha_trabajada DATE NOT NULL, -- el domingo/festivo que genero el derecho
    clasificacion ENUM('ocasional', 'habitual') NOT NULL,
    tratamiento ENUM('recargo', 'descanso_compensatorio', 'ambos') NOT NULL DEFAULT 'recargo',
    descanso_tomado_fecha DATE NULL, -- NULL = descanso pendiente de tomar (si aplica)
    comentario VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dias_compensatorios_empleado_fecha (empleado_id, fecha_trabajada),
    KEY idx_dias_compensatorios_pendientes (empleado_id, descanso_tomado_fecha),
    CONSTRAINT fk_diascompensatorios_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
