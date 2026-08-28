INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('registros_tiempo.corregir', 'Editar, eliminar o agregar manualmente marcaciones de entrada/salida', 'registros_tiempo');

-- Restringido a RRHH y Administrador: es una correccion sobre un registro de
-- auditoria (hora de servidor + geolocalizacion), no una tarea operativa del
-- dia a dia. Supervisor puede VER (registros_tiempo.ver) pero no corregir;
-- Auditor es de solo lectura por diseno.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'registros_tiempo.corregir')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH');
