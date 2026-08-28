<?php

/**
 * Aplica las migraciones pendientes en database/migrations/*.sql, en orden,
 * llevando control en la tabla `migrations`. Idempotente: se puede correr
 * tantas veces como se quiera, solo aplica lo que falte.
 *
 * Uso: php scripts/migrate.php
 *
 * La logica vive en App\Core\Migrator (compartida con el asistente de
 * instalacion web en /instalar), este script solo la invoca desde CLI.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Migrator;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$pdo = Database::connection();
$migrationsDir = __DIR__ . '/../database/migrations';

try {
    $aplicadas = Migrator::migrar($pdo, $migrationsDir);
} catch (\PDOException $e) {
    fwrite(STDERR, 'ERROR aplicando migraciones: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($aplicadas as $nombre) {
    echo "Aplicada: {$nombre}\n";
}

echo count($aplicadas) > 0
    ? count($aplicadas) . " migracion(es) aplicada(s).\n"
    : "No habia migraciones pendientes.\n";
