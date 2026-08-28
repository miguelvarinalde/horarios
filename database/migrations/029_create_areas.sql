-- Areas/equipos organizacionales (ej. Mantenimiento, Sistemas), que
-- reemplazan a empleados.supervisor_id como mecanismo de alcance de
-- "equipo": un Supervisor ve/gestiona solo a los empleados de su misma
-- area, salvo que su rol tenga el permiso equipos.ver_todas (RRHH,
-- Administrador y Auditor lo tienen por defecto). supervisor_id se deja
-- intacto para uso organizativo (jefe directo), pero deja de controlar
-- acceso.
CREATE TABLE IF NOT EXISTS areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_areas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE empleados
    ADD COLUMN area_id INT UNSIGNED NULL AFTER supervisor_id,
    ADD CONSTRAINT fk_empleados_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL;
