<?php

namespace App\Models;

use App\Core\Database;

class ConfiguracionSitioModel
{
    private const VALORES_POR_DEFECTO = ['nombre_aplicacion' => 'Gestion de Horarios', 'logo_path' => null, 'footer_texto' => null];

    /**
     * Se usa en el layout general y en la pantalla de login, es decir, en
     * TODAS las paginas del sitio (incluida /admin/actualizaciones). Por
     * eso nunca debe lanzar una excepcion: si la tabla configuracion_sitio
     * todavia no existe (ej. se subieron los archivos nuevos pero aun no se
     * ha aplicado la migracion desde /admin/actualizaciones), el sitio debe
     * seguir funcionando con los valores por defecto en vez de romperse por
     * completo y dejar al usuario sin poder ni siquiera iniciar sesion para
     * llegar a la pantalla que aplicaria la migracion.
     */
    public static function obtener(): array
    {
        try {
            $row = Database::connection()->query('SELECT * FROM configuracion_sitio WHERE id = 1')->fetch();
        } catch (\Throwable $e) {
            return self::VALORES_POR_DEFECTO;
        }
        return $row ?: self::VALORES_POR_DEFECTO;
    }

    public static function guardarTexto(string $nombreAplicacion, ?string $footerTexto): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE configuracion_sitio SET nombre_aplicacion = ?, footer_texto = ? WHERE id = 1'
        );
        $stmt->execute([$nombreAplicacion, $footerTexto]);
    }

    public static function guardarLogo(string $logoPath): void
    {
        $stmt = Database::connection()->prepare('UPDATE configuracion_sitio SET logo_path = ? WHERE id = 1');
        $stmt->execute([$logoPath]);
    }

    public static function eliminarLogo(): void
    {
        Database::connection()->exec('UPDATE configuracion_sitio SET logo_path = NULL WHERE id = 1');
    }
}
