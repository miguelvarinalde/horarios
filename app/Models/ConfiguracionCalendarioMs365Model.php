<?php

namespace App\Models;

use App\Core\Database;

class ConfiguracionCalendarioMs365Model
{
    public static function obtener(): array
    {
        $row = Database::connection()->query('SELECT * FROM configuracion_calendario_ms365 WHERE id = 1')->fetch();
        return $row ?: ['tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'activo' => 0];
    }

    /**
     * Actualiza tenant_id/client_id/activo siempre; client_secret solo si
     * se envia un valor no vacio (mismo patron que ConfiguracionMs365Model).
     */
    public static function guardar(string $tenantId, string $clientId, ?string $clientSecret, bool $activo): void
    {
        $db = Database::connection();

        if ($clientSecret !== null && $clientSecret !== '') {
            $stmt = $db->prepare(
                'UPDATE configuracion_calendario_ms365 SET tenant_id = ?, client_id = ?, client_secret = ?, activo = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $clientSecret, $activo ? 1 : 0]);
        } else {
            $stmt = $db->prepare(
                'UPDATE configuracion_calendario_ms365 SET tenant_id = ?, client_id = ?, activo = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $activo ? 1 : 0]);
        }
    }
}
