<?php

namespace App\Core;

class View
{
    private static string $layout = 'layouts/app';
    private static string $viewsPath;

    private static function basePath(): string
    {
        if (!isset(self::$viewsPath)) {
            self::$viewsPath = dirname(__DIR__) . '/Views';
        }
        return self::$viewsPath;
    }

    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderRaw($view, $data);

        if ($layout === null) {
            return $content;
        }

        $data['content'] = $content;
        return self::renderRaw($layout, $data);
    }

    public static function renderRaw(string $view, array $data = []): string
    {
        $file = self::basePath() . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Vista no encontrada: {$view} ({$file})");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * URL de un recurso estatico de /public con su fecha de modificacion como
     * version (?v=...). Sin esto el navegador (o Cloudflare) puede seguir
     * sirviendo un app.js/app.css viejo despues de un despliegue y las
     * pantallas nuevas quedan sin su JS; al cambiar el archivo cambia la URL.
     */
    public static function asset(string $ruta): string
    {
        $archivo = dirname(__DIR__, 2) . '/public' . $ruta;
        $version = is_file($archivo) ? (int) filemtime($archivo) : 0;
        return $version ? $ruta . '?v=' . $version : $ruta;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
