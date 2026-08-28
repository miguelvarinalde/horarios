-- Administrador: todos los permisos existentes.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Administrador'), p.id FROM permisos p;

-- RRHH: operacion diaria completa (empleados, horarios, novedades, reportes,
-- calculo, calendario) mas catalogos operativos (festivos, periodos no
-- laborables, tipos de novedad). NO incluye admin.configuracion ni
-- admin.tipos_recargo ni admin.roles: los porcentajes legales, la jornada
-- semanal/hora nocturna y la administracion de roles quedan reservados a
-- Administrador para evitar cambios accidentales en las reglas legales.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'RRHH'), p.id FROM permisos p
WHERE p.codigo IN (
    'admin.festivos', 'admin.periodos_no_laborables', 'admin.tipos_novedad',
    'empleados.ver', 'empleados.crear', 'empleados.editar',
    'horarios.ver', 'horarios.crear', 'horarios.editar',
    'novedades.ver', 'novedades.crear', 'novedades.aprobar',
    'calculo.ejecutar',
    'reportes.ver', 'reportes.exportar',
    'calendario.ver'
);

-- Supervisor: consulta de su equipo y aprobacion de novedades (el alcance a
-- "su equipo" se filtra en la aplicacion via empleados.supervisor_id, no
-- aqui, ya que RBAC solo controla que accion puede hacer, no sobre que filas).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Supervisor'), p.id FROM permisos p
WHERE p.codigo IN (
    'empleados.ver',
    'horarios.ver',
    'novedades.ver', 'novedades.aprobar',
    'reportes.ver',
    'calendario.ver'
);

-- Empleado: autoservicio basico (su propio horario y sus propias novedades).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Empleado'), p.id FROM permisos p
WHERE p.codigo IN (
    'horarios.ver',
    'novedades.ver', 'novedades.crear',
    'calendario.ver'
);

-- Auditor: solo lectura en todo el sistema, sin crear/editar/aprobar nada.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Auditor'), p.id FROM permisos p
WHERE p.codigo IN (
    'empleados.ver',
    'horarios.ver',
    'novedades.ver',
    'reportes.ver', 'reportes.exportar',
    'calendario.ver'
);
