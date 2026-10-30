-- Agrega la categoria 'almuerzo_no_tomado': una novedad aprobada de esta categoria
-- indica que ese dia el colaborador NO tomo el almuerzo, asi que no se le descuenta
-- la hora de almuerzo automatica (ver descontarAlmuerzo() en los dos motores de
-- calculo). No suma ni resta tiempo por si misma.
ALTER TABLE tipos_novedad
    MODIFY COLUMN categoria ENUM('permiso', 'vacaciones', 'incapacidad', 'hora_extra', 'ausencia', 'festivo_trabajado', 'descanso_compensatorio', 'almuerzo_no_tomado') NOT NULL;
