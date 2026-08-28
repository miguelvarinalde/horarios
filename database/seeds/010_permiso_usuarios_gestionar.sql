INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('usuarios.gestionar', 'Ver usuarios y resetear sus contrasenas', 'usuarios');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, (SELECT id FROM permisos WHERE codigo = 'usuarios.gestionar')
FROM roles r WHERE r.nombre IN ('Administrador', 'RRHH');
