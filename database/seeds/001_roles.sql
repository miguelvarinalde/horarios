INSERT IGNORE INTO roles (nombre, descripcion, es_sistema) VALUES
    ('Administrador', 'Configura variables globales del sistema: jornada, recargos, festivos, roles y permisos.', 1),
    ('RRHH', 'Gestiona empleados, horarios, novedades y reportes de nomina.', 1),
    ('Supervisor', 'Aprueba/rechaza novedades de su equipo y consulta horarios de sus subordinados.', 1),
    ('Empleado', 'Consulta su horario y solicita novedades (permisos, vacaciones).', 1),
    ('Auditor', 'Acceso de solo lectura a reportes y datos del sistema.', 1);
