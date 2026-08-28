CREATE TABLE IF NOT EXISTS permisos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(100) NOT NULL, -- ej. 'novedades.crear', 'reportes.ver', 'admin.configuracion'
    nombre VARCHAR(150) NOT NULL,
    modulo VARCHAR(60) NOT NULL, -- agrupacion para la UI de administracion de roles
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permisos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
