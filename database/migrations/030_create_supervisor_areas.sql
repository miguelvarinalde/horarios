-- Areas ADICIONALES que un usuario puede supervisar, ademas de la propia
-- (empleados.area_id). Antes del alcance de un Supervisor era binario: solo
-- su propia area, o (con el permiso equipos.ver_todas) TODAS las areas de
-- la empresa. Esto agrega un punto intermedio: un supervisor puede quedar
-- asignado a varias areas puntuales sin necesitar equipos.ver_todas (que
-- le daria acceso a absolutamente todo). Varios supervisores por area ya
-- era posible sin ningun cambio (basta con que compartan area_id).
--
-- Se referencia por usuario_id (no empleado_id): el alcance de "que puedo
-- ver" siempre se resuelve a partir de la sesion (Auth::id()), igual que
-- los permisos/roles. Se administra desde el formulario de edicion de
-- empleado (junto a area_id), solo visible si el empleado ya tiene una
-- cuenta de usuario vinculada.
CREATE TABLE IF NOT EXISTS supervisor_areas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    area_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supervisor_areas (usuario_id, area_id),
    CONSTRAINT fk_supervisorareas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_supervisorareas_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
