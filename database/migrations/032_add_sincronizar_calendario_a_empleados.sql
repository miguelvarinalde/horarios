-- database/migrations/032_add_sincronizar_calendario_a_empleados.sql
ALTER TABLE empleados ADD COLUMN sincronizar_calendario TINYINT(1) NOT NULL DEFAULT 0 AFTER area_id;
