-- Descuento automatico de hora de almuerzo. Se agrega a configuracion_global
-- (no a una tabla aparte) para seguir el mismo patron historizado por
-- vigente_desde que jornada_semanal_horas y la ventana de recargo nocturno:
-- si el horario de almuerzo cambia en el futuro, es una fila nueva, no un
-- cambio de codigo. Por defecto queda INACTIVO (almuerzo_activo=0) en todas
-- las filas existentes: activarlo y fijar el horario real es una decision
-- explicita que debe tomar el usuario desde /admin/configuracion, nunca un
-- valor supuesto por el sistema.
ALTER TABLE configuracion_global
    ADD COLUMN almuerzo_activo TINYINT(1) NOT NULL DEFAULT 0 AFTER hora_fin_recargo_nocturno,
    ADD COLUMN hora_inicio_almuerzo TIME NULL AFTER almuerzo_activo,
    ADD COLUMN hora_fin_almuerzo TIME NULL AFTER hora_inicio_almuerzo;
