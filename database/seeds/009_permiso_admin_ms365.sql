INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.ms365', 'Configurar la integracion Microsoft 365 (SSO)', 'admin');

-- Reservado solo a Administrador: incluye un client secret, no es una
-- configuracion operativa como jornada/festivos que RRHH deba tocar.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), (SELECT id FROM permisos WHERE codigo = 'admin.ms365');
