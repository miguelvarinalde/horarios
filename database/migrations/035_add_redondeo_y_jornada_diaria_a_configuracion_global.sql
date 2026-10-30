-- Dos reglas de calculo que se historizan igual que la jornada semanal (una fila
-- nueva por vigencia, nunca un valor fijo en el codigo):
--
--  * jornada_diaria_maxima_horas: tope de horas ORDINARIAS por dia (jornada
--    flexible, hasta 9 h). Lo que pase de ese tope en un mismo dia se paga como
--    hora extra aunque la semana no haya llegado a jornada_semanal_horas. NULL =
--    sin tope diario (solo cuenta el acumulado semanal, como antes).
--
--  * redondeo_minutos: bloque (en minutos) al que se redondean las marcaciones en
--    las columnas "redondeado" del informe de horas segun registro. 0 = sin
--    redondeo. Es solo informativo: nunca alimenta el motor legal por horario.
--
-- Las filas existentes quedan como estaban (sin tope diario y redondeo de 30 min,
-- que era el comportamiento anterior), salvo las vigentes desde 2026-07-15 (jornada
-- de 42 h), que pasan a 9 h diarias y redondeo de 15 min por decision del usuario.
ALTER TABLE configuracion_global
    ADD COLUMN jornada_diaria_maxima_horas DECIMAL(4,2) NULL AFTER jornada_semanal_horas,
    ADD COLUMN redondeo_minutos TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER hora_fin_almuerzo;

UPDATE configuracion_global
SET jornada_diaria_maxima_horas = 9.00, redondeo_minutos = 15
WHERE vigente_desde >= '2026-07-15';
