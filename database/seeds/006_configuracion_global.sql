-- Historizado segun la reduccion progresiva de jornada (Ley 2101 de 2021,
-- una reduccion cada 15 de julio) y el adelanto del inicio de la jornada
-- nocturna de 9:00pm a 7:00pm (Ley 2466 de 2025, vigente desde 2025-12-25).
-- jornada_diaria_maxima_horas (tope de horas ordinarias por dia, jornada flexible) y
-- redondeo_minutos (informes por registro) se agregaron en la migracion 035: desde
-- 2026-07-15 son 9 h y 15 min; antes, sin tope diario y 30 min.
-- IMPORTANTE: verificar contra la normativa vigente al momento de produccion.
INSERT IGNORE INTO configuracion_global (vigente_desde, jornada_semanal_horas, jornada_diaria_maxima_horas, redondeo_minutos, hora_inicio_recargo_nocturno, hora_fin_recargo_nocturno, notas) VALUES
    ('2023-07-15', 47.00, NULL, 30, '21:00:00', '06:00:00', 'Primera reduccion de jornada, Ley 2101 de 2021.'),
    ('2024-07-15', 46.00, NULL, 30, '21:00:00', '06:00:00', 'Segunda reduccion de jornada, Ley 2101 de 2021.'),
    ('2025-07-15', 44.00, NULL, 30, '21:00:00', '06:00:00', 'Tercera reduccion de jornada, Ley 2101 de 2021.'),
    ('2025-12-25', 44.00, NULL, 30, '19:00:00', '06:00:00', 'Ley 2466 de 2025: el recargo nocturno empieza a las 7:00pm en vez de 9:00pm.'),
    ('2026-07-15', 42.00, 9.00, 15, '19:00:00', '06:00:00', 'Jornada de 42h/semana totalmente implementada (Ley 2101 de 2021).');
