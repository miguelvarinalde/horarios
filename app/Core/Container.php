<?php

namespace App\Core;

use Closure;
use InvalidArgumentException;

/**
 * Contenedor de dependencias minimo: registra fabricas y resuelve
 * instancias compartidas (singleton) bajo demanda.
 */
class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $key, Closure $factory): void
    {
        $this->bindings[$key] = $factory;
    }

    public function singleton(string $key, Closure $factory): void
    {
        $this->bind($key, function (Container $c) use ($factory) {
            static $instance = null;
            if ($instance === null) {
                $instance = $factory($c);
            }
            return $instance;
        });
    }

    public function get(string $key)
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (!isset($this->bindings[$key])) {
            if (class_exists($key)) {
                return new $key();
            }
            throw new InvalidArgumentException("No hay binding registrado para [$key]");
        }

        return ($this->bindings[$key])($this);
    }
}
