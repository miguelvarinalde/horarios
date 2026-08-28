INSERT IGNORE INTO tipos_novedad (codigo, nombre, categoria, requiere_aprobacion, afecta_pago) VALUES
    ('PERMISO_REMUNERADO', 'Permiso remunerado', 'permiso', 1, 1),
    ('PERMISO_NO_REMUNERADO', 'Permiso no remunerado', 'permiso', 1, 1),
    ('VACACIONES', 'Vacaciones', 'vacaciones', 1, 1),
    ('INCAPACIDAD_EPS', 'Incapacidad medica (EPS)', 'incapacidad', 1, 1),
    ('INCAPACIDAD_ARL', 'Incapacidad laboral (ARL)', 'incapacidad', 1, 1),
    ('HORA_EXTRA', 'Horas extra trabajadas', 'hora_extra', 1, 1),
    ('FESTIVO_TRABAJADO', 'Trabajo en dia festivo/dominical', 'festivo_trabajado', 1, 1),
    ('AUSENCIA_INJUSTIFICADA', 'Ausencia injustificada', 'ausencia', 0, 1),
    ('DESCANSO_COMPENSATORIO', 'Dia de descanso compensatorio (por trabajo dominical/festivo)', 'descanso_compensatorio', 1, 1);
