<?php

namespace App\Core;

/**
 * Escritura segura de valores en un archivo .env. El instalador arma el
 * .env con texto escrito por el usuario; sin este filtro un valor con un
 * salto de linea podia inyectar variables nuevas (por ejemplo cambiar
 * APP_DEBUG o DB_HOST).
 */
class EnvFile
{
    /**
     * Devuelve el valor ya entre comillas dobles y con \, " y $ escapados,
     * listo para escribir como CLAVE="valor".
     *
     * @throws \InvalidArgumentException si contiene caracteres de control (saltos de linea, NUL, etc.)
     */
    public static function valor(string $valor): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $valor)) {
            throw new \InvalidArgumentException('El valor contiene caracteres no permitidos (saltos de linea o de control).');
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $valor) . '"';
    }
}
