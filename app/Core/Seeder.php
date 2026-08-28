<?php

namespace App\Core;

use App\Services\FestivosService;
use PDO;

/**
 * Logica de seeds compartida entre scripts/seed.php (CLI) y el asistente
 * de instalacion web, para no duplicarla en dos sitios.
 */
class Seeder
{
    /** @return string[] nombres de los archivos de seed aplicados */
    public static function aplicarSeeds(PDO $pdo, string $seedsDir): array
    {
        $files = glob($seedsDir . '/*.sql');
        sort($files, SORT_STRING);

        $aplicados = [];
        foreach ($files as $file) {
            $pdo->exec(file_get_contents($file));
            $aplicados[] = basename($file);
        }

        return $aplicados;
    }

    /** Crea el usuario Administrador inicial si no existe uno con ese correo. Devuelve su id. */
    public static function crearAdministrador(PDO $pdo, string $nombre, string $email, string $password): int
    {
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        if ($existente = $stmt->fetchColumn()) {
            return (int) $existente;
        }

        $rolAdminId = (int) $pdo->query("SELECT id FROM roles WHERE nombre = 'Administrador'")->fetchColumn();

        $insert = $pdo->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol_id, activo) VALUES (?, ?, ?, ?, 1)');
        $insert->execute([$nombre, $email, password_hash($password, PASSWORD_DEFAULT), $rolAdminId]);

        return (int) $pdo->lastInsertId();
    }

    /** Genera los festivos colombianos del año en curso y el siguiente. */
    public static function generarFestivos(): void
    {
        $servicio = new FestivosService();
        $anioActual = (int) date('Y');
        $servicio->generarYGuardarAnio($anioActual);
        $servicio->generarYGuardarAnio($anioActual + 1);
    }
}
