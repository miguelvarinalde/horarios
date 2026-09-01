<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $config = require dirname(__DIR__, 2) . '/config/database.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // Colombia no maneja horario de verano, asi que un offset fijo
                // es mas confiable que el nombre de zona horaria 'America/Bogota':
                // este ultimo depende de que el servidor MySQL tenga cargadas las
                // tablas de zonas horarias (mysql.time_zone_name), lo cual no esta
                // garantizado en hosting compartido. Sin este SET, NOW()/CURRENT_TIMESTAMP
                // quedan en la zona horaria que tenga configurada el servidor de MySQL,
                // que puede no coincidir con la hora de Bogota que ya usa PHP
                // (date_default_timezone_set en public/index.php).
                self::$instance->exec("SET time_zone = '-05:00'");
            } catch (PDOException $e) {
                throw new PDOException('No fue posible conectar a la base de datos: ' . $e->getMessage(), (int) $e->getCode());
            }
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
