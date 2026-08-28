<?php

namespace App\Core;

class Request
{
    private array $query;
    private array $body;
    private array $server;
    public array $routeParams = [];

    public function __construct(?array $query = null, ?array $body = null, ?array $server = null)
    {
        $this->query = $query ?? $_GET;
        $this->server = $server ?? $_SERVER;
        $this->body = $body ?? $this->parseBody();
    }

    private function parseBody(): array
    {
        $method = $this->method();
        if ($method === 'GET') {
            return [];
        }

        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            return json_decode($raw, true) ?? [];
        }

        return $_POST;
    }

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Soporte de _method para formularios HTML (PUT/PATCH/DELETE)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        return rawurldecode($path);
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key, $default = null)
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function isAjax(): bool
    {
        return strtolower($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public function server(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }
}
