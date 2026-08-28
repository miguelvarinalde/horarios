INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.areas', 'Administrar el catalogo de areas/equipos', 'admin'),
    ('equipos.ver_todas', 'Ver y gestionar empleados de todas las areas (no solo la propia)', 'equipos');

-- Administrar el catalogo de areas: reservado a Administrador, igual que
-- otros catalogos estructurales (tipos de recargo, roles).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), (SELECT id FROM permisos WHERE codigo = 'admin.areas');

-- equipos.ver_todas: quien lo tenga ve/gestiona todos los empleados sin
-- importar su area. Administrador, RRHH y Auditor lo tienen por defecto
-- (necesitan visibilidad total); Supervisor y Empleado NO lo tienen, asi
-- que quedan acotados a su propia area. Es un permiso normal: se puede
-- ajustar despues desde Roles y permisos sin tocar codigo.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'equipos.ver_todas')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH', 'Auditor');
