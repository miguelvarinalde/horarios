<?php

namespace App\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

class Router
{
    /** @var array<int, array{method:string, path:string, handler:mixed, middleware:array}> */
    private array $routes = [];

    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, $handler, array $middleware): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'middleware');
    }

    public function dispatch(Container $container, Request $request): void
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute($route['method'], $route['path'], $route);
            }
        });

        $path = $request->path();
        // Normaliza rutas si la app vive en un subdirectorio (BlueHost addon domain, etc.)
        $basePath = self::basePath();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
            if ($path === '') {
                $path = '/';
            }
        }

        $routeInfo = $dispatcher->dispatch($request->method(), rtrim($path, '/') ?: '/');

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                Response::abort(404, 'Pagina no encontrada');
                return;

            case Dispatcher::METHOD_NOT_ALLOWED:
                Response::abort(405, 'Metodo no permitido');
                return;

            case Dispatcher::FOUND:
                $route = $routeInfo[1];
                $request->routeParams = $routeInfo[2];

                foreach ($route['middleware'] as $middlewareEntry) {
                    /** @var callable $middleware */
                    if (is_string($middlewareEntry)) {
                        $middleware = new $middlewareEntry();
                    } elseif (is_array($middlewareEntry) && is_string($middlewareEntry[0]) && class_exists($middlewareEntry[0])) {
                        // Soporta middleware parametrizado, ej. [RbacMiddleware::class, 'novedades.aprobar']
                        $class = $middlewareEntry[0];
                        $args = array_slice($middlewareEntry, 1);
                        $middleware = new $class(...$args);
                    } else {
                        $middleware = $middlewareEntry;
                    }

                    $result = $middleware($request);
                    if ($result === false) {
                        return; // el middleware ya envio una respuesta (redirect/abort)
                    }
                }

                $handler = $route['handler'];
                if (is_array($handler) && is_string($handler[0])) {
                    $controller = $container->get($handler[0]);
                    $handler = [$controller, $handler[1]];
                }

                echo $handler($request);
                return;
        }
    }

    private static function basePath(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $url = $config['url'] ?? '';
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return rtrim($path, '/');
    }
}
