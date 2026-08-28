<?php

namespace App\Core;

use PDO;

/**
 * Logica de migraciones compartida entre scripts/migrate.php (CLI) y el
 * asistente de instalacion web (App\Controllers\InstalacionController),
 * para no duplicarla en dos sitios.
 */
class Migrator
{
    /** @return string[] nombres de las migraciones recien aplicadas */
    public static function migrar(PDO $pdo, string $migrationsDir): array
    {
        $pdo->exec(file_get_contents($migrationsDir . '/001_create_migrations.sql'));

        $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($migrationsDir . '/*.sql');
        sort($files, SORT_STRING);

        $aplicadas = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $pdo->exec(file_get_contents($file));

            $stmt = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
            $stmt->execute([$name]);
            $aplicadas[] = $name;
        }

        return $aplicadas;
    }

    /** @return string[] nombres de migraciones que existen en disco pero no se han aplicado, sin aplicarlas */
    public static function pendientes(PDO $pdo, string $migrationsDir): array
    {
        $pdo->exec(file_get_contents($migrationsDir . '/001_create_migrations.sql'));

        $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($migrationsDir . '/*.sql');
        sort($files, SORT_STRING);

        $pendientes = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!in_array($name, $applied, true)) {
                $pendientes[] = $name;
            }
        }

        return $pendientes;
    }
}
