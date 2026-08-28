INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('admin.configuracion', 'Editar jornada semanal y ventana de recargo nocturno', 'admin'),
    ('admin.roles', 'Administrar roles y permisos', 'admin'),
    ('admin.festivos', 'Administrar festivos', 'admin'),
    ('admin.periodos_no_laborables', 'Administrar periodos no laborables de empresa', 'admin'),
    ('admin.tipos_novedad', 'Administrar catalogo de tipos de novedad', 'admin'),
    ('admin.tipos_recargo', 'Administrar matriz de porcentajes de recargo', 'admin'),

    ('empleados.ver', 'Ver empleados', 'empleados'),
    ('empleados.crear', 'Crear empleados', 'empleados'),
    ('empleados.editar', 'Editar empleados', 'empleados'),

    ('horarios.ver', 'Ver horarios base', 'horarios'),
    ('horarios.crear', 'Crear horarios base', 'horarios'),
    ('horarios.editar', 'Editar horarios base', 'horarios'),

    ('novedades.ver', 'Ver novedades', 'novedades'),
    ('novedades.crear', 'Registrar/solicitar novedades', 'novedades'),
    ('novedades.aprobar', 'Aprobar o rechazar novedades', 'novedades'),

    ('calculo.ejecutar', 'Ejecutar el motor de calculo de recargos', 'calculo'),

    ('reportes.ver', 'Ver reportes de horas extra y recargos', 'reportes'),
    ('reportes.exportar', 'Exportar reportes a Excel/PDF', 'reportes'),

    ('calendario.ver', 'Ver calendario de equipo', 'calendario');
