-- database/migrations/031_create_configuracion_calendario_ms365.sql
-- Configuracion del App Registration de calendario (permiso de aplicacion,
-- independiente del App Registration de login SSO en configuracion_ms365).
-- Fila unica (id=1), igual patron que configuracion_ms365.
CREATE TABLE IF NOT EXISTS configuracion_calendario_ms365 (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(100) NULL,
    client_id VARCHAR(100) NULL,
    client_secret VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion_calendario_ms365 (id) VALUES (1);
