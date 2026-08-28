-- Proteccion basica contra fuerza bruta en el login interno: bloquea la
-- cuenta temporalmente tras varios intentos fallidos seguidos.
ALTER TABLE usuarios
    ADD COLUMN intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash,
    ADD COLUMN bloqueado_hasta DATETIME NULL AFTER intentos_fallidos;
