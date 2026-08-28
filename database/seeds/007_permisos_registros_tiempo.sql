INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('registros_tiempo.marcar', 'Marcar su propia entrada/salida', 'registros_tiempo'),
    ('registros_tiempo.ver', 'Ver registros de entrada/salida de otros empleados', 'registros_tiempo');

-- Cualquier rol puede ser, ademas, una persona que marca su propio tiempo
-- (incluida RRHH/Supervisor/Administrador). Auditor queda excluido de marcar
-- por ser un rol de solo lectura sin empleado operativo asociado.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'registros_tiempo.marcar')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH', 'Supervisor', 'Empleado');

-- Ver el registro de otros: RRHH, Supervisor (alcance de su equipo, filtrado
-- en la aplicacion) y Auditor.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'registros_tiempo.ver')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH', 'Supervisor', 'Auditor');
