<?php

namespace App\Models;

use App\Core\Model;

class UsuarioMs365Model extends Model
{
    protected static string $table = 'usuarios_ms365';

    public static function porAzureOid(string $azureOid): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM usuarios_ms365 WHERE azure_oid = ? LIMIT 1');
        $stmt->execute([$azureOid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function porUsuario(int $usuarioId): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM usuarios_ms365 WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function vincular(int $usuarioId, string $azureOid, string $upn): int
    {
        return static::insert([
            'usuario_id' => $usuarioId,
            'azure_oid' => $azureOid,
            'upn' => $upn,
            'ultimo_login_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function actualizarVinculo(int $id, string $azureOid, string $upn): void
    {
        static::update($id, [
            'azure_oid' => $azureOid,
            'upn' => $upn,
            'ultimo_login_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function marcarLogin(int $id): void
    {
        static::update($id, ['ultimo_login_at' => date('Y-m-d H:i:s')]);
    }
}
