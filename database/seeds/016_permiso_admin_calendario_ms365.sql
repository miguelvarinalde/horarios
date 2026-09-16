-- database/seeds/016_permiso_admin_calendario_ms365.sql
INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.calendario_ms365', 'Configurar la sincronizacion de calendario Microsoft 365', 'admin');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), (SELECT id FROM permisos WHERE codigo = 'admin.calendario_ms365');
