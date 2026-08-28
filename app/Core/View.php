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

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
