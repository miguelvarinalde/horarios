-- Personalizacion del sitio (nombre/logo mostrados en el encabezado y texto
-- de pie de pagina), editable desde /admin/sitio sin tocar codigo ni
-- redesplegar. Fila unica (id=1), mismo patron que configuracion_ms365.
CREATE TABLE IF NOT EXISTS configuracion_sitio (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    nombre_aplicacion VARCHAR(120) NOT NULL DEFAULT 'Gestion de Horarios',
    logo_path VARCHAR(255) NULL, -- nombre de archivo dentro de public/uploads/logo/, NULL = sin logo (solo texto)
    footer_texto VARCHAR(500) NULL, -- texto plano (se escapa al mostrar; no admite HTML)
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion_sitio (id, nombre_aplicacion) VALUES (1, 'Gestion de Horarios');
