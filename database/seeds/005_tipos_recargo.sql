-- Matriz de recargos, historizada segun la evolucion real de la ley:
-- HED (25%), HEN (75%), RN (35%) y ORD (0%) no han cambiado (art. 168-179
-- CST), por lo que llevan una sola fila vigente desde 2020-01-01.
--
-- El recargo dominical/festivo (y sus combinaciones aditivas con hora extra
-- y/o nocturno) SI cambio con la Ley 2466 de 2025, que establecio un
-- incremento progresivo:
--   hasta 2025-06-30: 75%
--   2025-07-01 a 2026-06-30: 80%
--   2026-07-01 a 2027-06-30: 90%  (vigente actualmente)
--   desde 2027-07-01: 100%
-- Combinaciones = RDF vigente + HED/HEN/RN correspondiente (los recargos se
-- suman cuando concurren varias condiciones, segun la doctrina laboral).
--
-- IMPORTANTE: verificar estos valores contra la normativa vigente al momento
-- de poner el sistema en produccion; la reforma laboral (Ley 2466 de 2025)
-- ha tenido varios ajustes y esta tabla debe revisarse periodicamente.
INSERT IGNORE INTO tipos_recargo (codigo, nombre, es_hora_extra, es_nocturno, es_dominical_festivo, porcentaje, vigente_desde) VALUES
    ('ORD',   'Hora ordinaria (diurna, entre semana, dentro de jornada)', 0, 0, 0,   0.00, '2020-01-01'),
    ('RN',    'Recargo nocturno',                                        0, 1, 0,  35.00, '2020-01-01'),
    ('HED',   'Hora extra diurna',                                       1, 0, 0,  25.00, '2020-01-01'),
    ('HEN',   'Hora extra nocturna',                                     1, 1, 0,  75.00, '2020-01-01'),

    ('RDF',   'Recargo dominical/festivo (diurno, ordinario)',           0, 0, 1,  75.00, '2020-01-01'),
    ('RNDF',  'Recargo nocturno dominical/festivo (ordinario)',          0, 1, 1, 110.00, '2020-01-01'),
    ('HEDDF', 'Hora extra diurna en dominical/festivo',                  1, 0, 1, 100.00, '2020-01-01'),
    ('HENDF', 'Hora extra nocturna en dominical/festivo',                1, 1, 1, 150.00, '2020-01-01'),

    ('RDF',   'Recargo dominical/festivo (diurno, ordinario)',           0, 0, 1,  80.00, '2025-07-01'),
    ('RNDF',  'Recargo nocturno dominical/festivo (ordinario)',          0, 1, 1, 115.00, '2025-07-01'),
    ('HEDDF', 'Hora extra diurna en dominical/festivo',                  1, 0, 1, 105.00, '2025-07-01'),
    ('HENDF', 'Hora extra nocturna en dominical/festivo',                1, 1, 1, 155.00, '2025-07-01'),

    ('RDF',   'Recargo dominical/festivo (diurno, ordinario)',           0, 0, 1,  90.00, '2026-07-01'),
    ('RNDF',  'Recargo nocturno dominical/festivo (ordinario)',          0, 1, 1, 125.00, '2026-07-01'),
    ('HEDDF', 'Hora extra diurna en dominical/festivo',                  1, 0, 1, 115.00, '2026-07-01'),
    ('HENDF', 'Hora extra nocturna en dominical/festivo',                1, 1, 1, 165.00, '2026-07-01'),

    ('RDF',   'Recargo dominical/festivo (diurno, ordinario)',           0, 0, 1, 100.00, '2027-07-01'),
    ('RNDF',  'Recargo nocturno dominical/festivo (ordinario)',          0, 1, 1, 135.00, '2027-07-01'),
    ('HEDDF', 'Hora extra diurna en dominical/festivo',                  1, 0, 1, 125.00, '2027-07-01'),
    ('HENDF', 'Hora extra nocturna en dominical/festivo',                1, 1, 1, 175.00, '2027-07-01');
