INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('dias_compensatorios.ver', 'Ver dias compensatorios por trabajo dominical/festivo', 'dias_compensatorios'),
    ('dias_compensatorios.gestionar', 'Elegir recargo o descanso compensatorio, y marcar descanso tomado', 'dias_compensatorios');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'dias_compensatorios.ver')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH', 'Supervisor', 'Auditor');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'dias_compensatorios.gestionar')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH');
