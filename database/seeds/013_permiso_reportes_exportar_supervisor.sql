-- Supervisor ya podia VER "Horas extra y recargos" (reportes.ver), pero no
-- exportarlo. Con el reporte ahora acotado a su propio equipo (ver
-- ReporteController::empleadoIdsPermitidos), tiene sentido que tambien
-- pueda exportarlo para su gente, igual que RRHH/Administrador/Auditor.
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Supervisor'), (SELECT id FROM permisos WHERE codigo = 'reportes.exportar');
