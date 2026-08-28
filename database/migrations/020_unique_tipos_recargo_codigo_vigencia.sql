-- Evita duplicados: dos filas no pueden tener el mismo codigo de recargo
-- vigente desde la misma fecha (bug real: al no existir esta restriccion,
-- correr seed.php mas de una vez duplico cada fila de tipos_recargo).
ALTER TABLE tipos_recargo
    ADD UNIQUE KEY uq_tipos_recargo_codigo_vigente_desde (codigo, vigente_desde);
