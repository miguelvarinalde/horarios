<?php

/**
 * Aplica los catalogos base (roles, permisos, tipos_novedad, tipos_recargo,
 * configuracion_global) desde database/seeds/*.sql (idempotente via INSERT
 * IGNORE), crea el usuario Administrador inicial si no existe, y genera los
 * festivos del año en curso y el siguiente.
 *
 * Uso:
 *   php scripts/seed.php
 *   php scripts/seed.php admin@empresa.com "ClaveSegura123!" "Nombre Administrador"
 *
 * Si no se pasan credenciales, se crea admin@example.com con una clave
 * temporal aleatoria que se imprime una sola vez en consola.
 *
 * La logica vive en App\Core\Seeder (compartida con el asistente de
 * instalacion web en /instalar), este script solo la invoca desde CLI.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\Seeder;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$pdo = Database::connection();
$seedsDir = __DIR__ . '/../database/seeds';

try {
    foreach (Seeder::aplicarSeeds($pdo, $seedsDir) as $nombre) {
        echo "Aplicado: {$nombre}\n";
    }
} catch (\PDOException $e) {
    fwrite(STDERR, 'ERROR aplicando seeds: ' . $e->getMessage() . "\n");
    exit(1);
}

$email = $argv[1] ?? 'admin@example.com';
$passwordGenerada = false;
$password = $argv[2] ?? null;
if ($password === null) {
    $password = bin2hex(random_bytes(6));
    $passwordGenerada = true;
}
$nombre = $argv[3] ?? 'Administrador';

$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
$stmt->execute([$email]);
$existente = (bool) $stmt->fetchColumn();

Seeder::crearAdministrador($pdo, $nombre, $email, $password);

if ($existente) {
    echo "El usuario {$email} ya existia, no se modifico.\n";
} else {
    echo "Usuario Administrador creado: {$email}\n";
    if ($passwordGenerada) {
        echo "Clave temporal (cambiarla de inmediato al iniciar sesion): {$password}\n";
    }
}

Seeder::generarFestivos();
echo 'Festivos ' . date('Y') . ' y ' . (date('Y') + 1) . " generados/actualizados.\n";

echo "Seed completado.\n";
