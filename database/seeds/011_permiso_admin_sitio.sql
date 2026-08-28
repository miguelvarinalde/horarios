INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.sitio', 'Personalizar nombre, logo y pie de pagina del sitio', 'admin');

-- Reservado a Administrador: es branding institucional de todo el sitio, no
-- una configuracion operativa del dia a dia como festivos o tipos de novedad.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), (SELECT id FROM permisos WHERE codigo = 'admin.sitio');
