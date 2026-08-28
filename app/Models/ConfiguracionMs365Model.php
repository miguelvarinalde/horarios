<?php

namespace App\Models;

use App\Core\Database;

class ConfiguracionMs365Model
{
    public static function obtener(): array
    {
        $row = Database::connection()->query('SELECT * FROM configuracion_ms365 WHERE id = 1')->fetch();
        return $row ?: ['tenant_id' => null, 'client_id' => null, 'client_secret' => null, 'redirect_uri' => null];
    }

    public static function configurado(): bool
    {
        $c = self::obtener();
        return !empty($c['tenant_id']) && !empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['redirect_uri']);
    }

    /**
     * Actualiza tenant_id/client_id/redirect_uri siempre; client_secret solo
     * si se envia un valor no vacio (dejarlo en blanco en el formulario
     * conserva el secreto ya guardado, para no tener que volver a pegarlo
     * cada vez que se edite cualquier otro campo).
     */
    public static function guardar(string $tenantId, string $clientId, ?string $clientSecret, string $redirectUri): void
    {
        $db = Database::connection();

        if ($clientSecret !== null && $clientSecret !== '') {
            $stmt = $db->prepare(
                'UPDATE configuracion_ms365 SET tenant_id = ?, client_id = ?, client_secret = ?, redirect_uri = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $clientSecret, $redirectUri]);
        } else {
            $stmt = $db->prepare(
                'UPDATE configuracion_ms365 SET tenant_id = ?, client_id = ?, redirect_uri = ? WHERE id = 1'
            );
            $stmt->execute([$tenantId, $clientId, $redirectUri]);
        }
    }
}
