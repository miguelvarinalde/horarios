-- Permiso para que cada colaborador revise y exporte SUS PROPIAS horas
-- registradas (informe "Horas trabajadas segun registro"), solo del mes
-- anterior y el mes actual. No da acceso a los demas reportes (horas extra,
-- nomina) ni a las horas de otras personas.
--
-- IMPORTANTE: se concede a TODOS los roles, no solo a Empleado. Un usuario
-- solo puede gestionar (resetear clave, cambiar correo, dar de baja) o crear
-- cuentas de roles cuyos permisos ya tiene (ver PrivilegiosService): si
-- Empleado tuviera un permiso que RRHH o Supervisor no tienen, ellos dejarian
-- de poder administrar a los Empleados. Quien ya tiene reportes.ver sigue
-- viendo el informe completo; este permiso solo importa para quien no lo tiene.
INSERT IGNORE INTO permisos (codigo, nombre, modulo) VALUES
    ('reportes.horas_propias', 'Ver y exportar mis propias horas registradas (mes anterior y actual)', 'reportes');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
FROM roles r
JOIN permisos p ON p.codigo = 'reportes.horas_propias'
WHERE r.nombre IN ('Administrador', 'RRHH', 'Supervisor', 'Empleado', 'Auditor');
