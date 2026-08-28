-- Antes solo Administrador/RRHH podian crear periodos de calculo y
-- ejecutar/recalcular el motor de recargos. Se extiende a Supervisor a
-- pedido del usuario ("inicialmente para colocar este permiso a
-- supervisores, aunque puede extenderse a otros perfiles" — de ahi en
-- adelante es solo un permiso normal, ajustable desde /admin/roles sin
-- tocar codigo). El recalculo queda automaticamente acotado a la propia
-- area de quien lo ejecuta si no tiene equipos.ver_todas (ver
-- ReporteController::calcular() / CalculoRecargosService::calcularPeriodoTodosLosEmpleados()).
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT (SELECT id FROM roles WHERE nombre = 'Supervisor'), (SELECT id FROM permisos WHERE codigo = 'calculo.ejecutar');
