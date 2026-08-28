<?php

namespace App\Models;

use App\Core\Model;

class UsuarioModel extends Model
{
    protected static string $table = 'usuarios';

    public static function findByEmail(string $email): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function withRol(int $id): ?array
    {
        $stmt = static::db()->prepare(
            'SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return string[] codigos de permiso del usuario, via su rol */
    public static function permisos(int $usuarioId): array
    {
        $stmt = static::db()->prepare(
            'SELECT p.codigo
             FROM usuarios u
             JOIN rol_permisos rp ON rp.rol_id = u.rol_id
             JOIN permisos p ON p.id = rp.permiso_id
             WHERE u.id = ?'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function marcarLogin(int $id): void
    {
        $stmt = static::db()->prepare(
            'UPDATE usuarios SET ultimo_login_at = NOW(), intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Registra un intento fallido de login. Al llegar al umbral, bloquea la
     * cuenta temporalmente (proteccion basica contra fuerza bruta).
     */
    public static function registrarIntentoFallido(int $id): void
    {
        $umbral = 5;
        $minutosBloqueo = 15;

        $stmt = static::db()->prepare(
            "UPDATE usuarios
             SET intentos_fallidos = intentos_fallidos + 1,
                 bloqueado_hasta = CASE
                     WHEN intentos_fallidos + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                     ELSE bloqueado_hasta
                 END
             WHERE id = ?"
        );
        $stmt->execute([$umbral, $minutosBloqueo, $id]);
    }

    public static function estaBloqueado(array $usuario): bool
    {
        return !empty($usuario['bloqueado_hasta']) && strtotime($usuario['bloqueado_hasta']) > time();
    }

    public static function registrarConsentimientoUbicacion(int $id): void
    {
        $stmt = static::db()->prepare('UPDATE usuarios SET consentimiento_ubicacion_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function todosConRol(): array
    {
        return static::db()->query(
            'SELECT u.*, r.nombre AS rol_nombre
             FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             ORDER BY u.nombre'
        )->fetchAll();
    }

    /**
     * Fija una nueva contrasena (Administrador/RRHH resetea, o el propio
     * usuario la cambia) y limpia cualquier bloqueo/intento fallido previo.
     * $forzarCambio=true obliga a elegir una nueva en el proximo login (uso
     * tipico al resetear); false se usa cuando el propio usuario ya la
     * acaba de cambiar voluntariamente.
     */
    public static function fijarPassword(int $id, string $passwordPlano, bool $forzarCambio): void
    {
        $stmt = static::db()->prepare(
            'UPDATE usuarios
             SET password_hash = ?, debe_cambiar_password = ?, intentos_fallidos = 0, bloqueado_hasta = NULL
             WHERE id = ?'
        );
        $stmt->execute([password_hash($passwordPlano, PASSWORD_DEFAULT), $forzarCambio ? 1 : 0, $id]);
    }

    /**
     * Un login exitoso por Microsoft 365 ya prueba la identidad por un medio
     * mas fuerte (SSO institucional): no tiene sentido seguir exigiendole a
     * ese usuario que cambie una contrasena interna que quizas nunca use.
     */
    public static function limpiarBanderaCambioPassword(int $id): void
    {
        $stmt = static::db()->prepare('UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }
}
