-- Matriz configurable de recargos. El motor de calculo (CalculoRecargosService)
-- evalua cada franja de tiempo trabajada con 3 banderas booleanas
-- (es_hora_extra, es_nocturno, es_dominical_festivo) y busca la fila cuyos
-- flags coincidan exactamente para tomar el porcentaje aplicable. Un cambio
-- legal futuro en un porcentaje es una fila nueva (con vigente_desde nueva),
-- nunca una edicion de codigo.
CREATE TABLE IF NOT EXISTS tipos_recargo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL, -- HED, HEN, RN, RDF, HEDDF, HENDF
    nombre VARCHAR(150) NOT NULL,
    es_hora_extra TINYINT(1) NOT NULL DEFAULT 0,
    es_nocturno TINYINT(1) NOT NULL DEFAULT 0,
    es_dominical_festivo TINYINT(1) NOT NULL DEFAULT 0,
    porcentaje DECIMAL(6,2) NOT NULL, -- ej. 25.00, 35.00, 75.00
    vigente_desde DATE NOT NULL,
    vigente_hasta DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tipos_recargo_flags (es_hora_extra, es_nocturno, es_dominical_festivo, vigente_desde),
    KEY idx_tipos_recargo_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
