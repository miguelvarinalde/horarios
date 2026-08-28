-- Vinculacion de un usuario interno con su cuenta Microsoft 365 / Entra ID
-- (Fase 2: SSO). Tabla separada de `usuarios` para no tocar el esquema de
-- Fase 1: un usuario puede seguir usando login interno (email+password)
-- aunque tambien tenga vinculo MS365, y viceversa.
CREATE TABLE IF NOT EXISTS usuarios_ms365 (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    azure_oid VARCHAR(64) NOT NULL, -- claim 'oid': identificador estable del usuario en el tenant de Entra ID
    upn VARCHAR(190) NOT NULL, -- userPrincipalName (normalmente el correo corporativo)
    ultimo_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_ms365_usuario (usuario_id),
    UNIQUE KEY uq_usuarios_ms365_azure_oid (azure_oid),
    CONSTRAINT fk_usuariosms365_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
