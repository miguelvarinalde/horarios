-- Configuracion de la integracion Microsoft 365 / Entra ID (Fase 2), editable
-- desde /admin/ms365 en vez de requerir tocar el archivo .env cada vez que
-- cambie (ej. rotacion del client secret). Fila unica (id=1).
CREATE TABLE IF NOT EXISTS configuracion_ms365 (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(100) NULL,
    client_id VARCHAR(100) NULL,
    client_secret VARCHAR(255) NULL,
    redirect_uri VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion_ms365 (id) VALUES (1);
