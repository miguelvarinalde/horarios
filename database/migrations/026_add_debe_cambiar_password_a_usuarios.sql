-- Fuerza el cambio de contrasena en el primer login cuando el Administrador/RRHH
-- crea el usuario o resetea su clave: asi nadie se queda con una contrasena
-- generada que nunca vio, ni un empleado usando indefinidamente una clave
-- temporal que le paso otra persona.
ALTER TABLE usuarios
    ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 AFTER bloqueado_hasta;
