-- Agrega la categoria 'descanso_compensatorio' para cuando el empleado toma
-- el dia libre que le corresponde por haber trabajado un domingo/festivo
-- (Ley 2466 de 2025). Ver 022_create_dias_compensatorios.sql.
ALTER TABLE tipos_novedad
    MODIFY COLUMN categoria ENUM('permiso', 'vacaciones', 'incapacidad', 'hora_extra', 'ausencia', 'festivo_trabajado', 'descanso_compensatorio') NOT NULL;
