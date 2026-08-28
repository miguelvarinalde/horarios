<?php

namespace App\Core;

class Response
{
    public static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
        exit;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function status(int $status): void
    {
        http_response_code($status);
    }

    public static function abort(int $status, string $message = ''): void
    {
        http_response_code($status);
        echo View::render('errors/generic', ['status' => $status, 'message' => $message]);
        exit;
    }
}
