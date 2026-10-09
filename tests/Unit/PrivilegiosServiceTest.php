<?php

namespace Tests\Unit;

use App\Services\PrivilegiosService;
use Tests\TestCase;

/**
 * Regla anti-escalada de privilegios (auditoria de seguridad 2026-10-09,
 * hallazgos 1, 2 y 5): un usuario solo puede gestionar a otro, o asignar un
 * rol, si TODOS los permisos de ese usuario/rol ya los tiene el mismo.
 * Se prueba contra los roles reales sembrados (Administrador, RRHH,
 * Supervisor, Empleado, Auditor).
 */
class PrivilegiosServiceTest extends TestCase
{
    private function rolId(string $nombre): int
    {
        $id = $this->db->prepare('SELECT id FROM roles WHERE nombre = ?');
        $id->execute([$nombre]);
        $valor = $id->fetchColumn();
        $this->assertNotFalse($valor, "No existe el rol {$nombre}: ¿se aplicaron los seeds?");
        return (int) $valor;
    }

    private function permisosDe(string $nombreRol): array
    {
        return PrivilegiosService::permisosDeRol($this->rolId($nombreRol));
    }

    /** Usuario de prueba con el rol indicado (se elimina en tearDown). */
    private function usuarioConRol(string $nombreRol): int
    {
        $usuarioId = $this->crearUsuarioDummy();
        $this->db->prepare('UPDATE usuarios SET rol_id = ? WHERE id = ?')->execute([$this->rolId($nombreRol), $usuarioId]);
        return $usuarioId;
    }

    public function test_rrhh_no_puede_gestionar_a_un_administrador(): void
    {
        $this->assertFalse(PrivilegiosService::puedeGestionarUsuario($this->permisosDe('RRHH'), $this->usuarioConRol('Administrador')));
    }

    public function test_administrador_puede_gestionar_a_cualquier_rol(): void
    {
        $admin = $this->permisosDe('Administrador');
        foreach (['Administrador', 'RRHH', 'Supervisor', 'Empleado', 'Auditor'] as $rol) {
            $this->assertTrue(
                PrivilegiosService::puedeGestionarUsuario($admin, $this->usuarioConRol($rol)),
                "El Administrador deberia poder gestionar a un usuario {$rol}"
            );
        }
    }

    public function test_rrhh_puede_gestionar_a_roles_con_menos_permisos_y_a_sus_pares(): void
    {
        $rrhh = $this->permisosDe('RRHH');
        foreach (['RRHH', 'Supervisor', 'Empleado', 'Auditor'] as $rol) {
            $this->assertTrue(
                PrivilegiosService::puedeGestionarUsuario($rrhh, $this->usuarioConRol($rol)),
                "RRHH deberia poder gestionar a un usuario {$rol}"
            );
        }
    }

    public function test_un_empleado_no_puede_gestionar_a_nadie_con_mas_permisos(): void
    {
        $empleado = $this->permisosDe('Empleado');
        $this->assertFalse(PrivilegiosService::puedeGestionarUsuario($empleado, $this->usuarioConRol('Supervisor')));
        $this->assertFalse(PrivilegiosService::puedeGestionarUsuario($empleado, $this->usuarioConRol('RRHH')));
    }

    public function test_rrhh_solo_puede_asignar_roles_contenidos_en_el_suyo(): void
    {
        $rrhh = $this->permisosDe('RRHH');

        $this->assertFalse(PrivilegiosService::puedeAsignarRol($rrhh, $this->rolId('Administrador')), 'RRHH no debe poder crear Administradores');
        $this->assertTrue(PrivilegiosService::puedeAsignarRol($rrhh, $this->rolId('Supervisor')));
        $this->assertTrue(PrivilegiosService::puedeAsignarRol($rrhh, $this->rolId('Empleado')));
        $this->assertTrue(PrivilegiosService::puedeAsignarRol($rrhh, $this->rolId('Auditor')));
    }

    public function test_un_rol_inexistente_nunca_es_asignable(): void
    {
        $this->assertFalse(PrivilegiosService::puedeAsignarRol($this->permisosDe('Administrador'), 99999999));
        $this->assertFalse(PrivilegiosService::puedeAsignarRol($this->permisosDe('Administrador'), 0));
    }

    public function test_el_administrador_puede_asignar_cualquier_rol(): void
    {
        $admin = $this->permisosDe('Administrador');
        foreach (['Administrador', 'RRHH', 'Supervisor', 'Empleado', 'Auditor'] as $rol) {
            $this->assertTrue(PrivilegiosService::puedeAsignarRol($admin, $this->rolId($rol)), "El Administrador deberia poder asignar {$rol}");
        }
    }

    public function test_roles_asignables_para_rrhh_excluye_administrador(): void
    {
        $nombres = array_column(PrivilegiosService::rolesAsignables($this->permisosDe('RRHH')), 'nombre');

        $this->assertNotContains('Administrador', $nombres);
        $this->assertContains('RRHH', $nombres);
        $this->assertContains('Supervisor', $nombres);
        $this->assertContains('Empleado', $nombres);
        $this->assertContains('Auditor', $nombres);
    }

    public function test_ids_gestionables_es_consistente_con_puedeGestionarUsuario(): void
    {
        $rrhh = $this->permisosDe('RRHH');
        $admin = $this->usuarioConRol('Administrador');
        $supervisor = $this->usuarioConRol('Supervisor');

        $ids = PrivilegiosService::idsUsuariosGestionables($rrhh);

        $this->assertNotContains($admin, $ids);
        $this->assertContains($supervisor, $ids);
    }

    public function test_un_rol_personalizado_con_un_permiso_extra_hereda_la_regla(): void
    {
        // Rol personalizado = permisos de RRHH + admin.roles: RRHH ya no puede asignarlo.
        $this->db->prepare("INSERT INTO roles (nombre, descripcion) VALUES ('zz_rol_prueba_privilegios', 'temporal')")->execute();
        $rolId = (int) $this->db->lastInsertId();
        try {
            foreach (array_merge($this->permisosDe('RRHH'), ['admin.roles']) as $codigo) {
                $this->db->prepare('INSERT INTO rol_permisos (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE codigo = ?')
                    ->execute([$rolId, $codigo]);
            }

            $this->assertFalse(PrivilegiosService::puedeAsignarRol($this->permisosDe('RRHH'), $rolId));
            $this->assertTrue(PrivilegiosService::puedeAsignarRol($this->permisosDe('Administrador'), $rolId));
        } finally {
            $this->db->prepare('DELETE FROM rol_permisos WHERE rol_id = ?')->execute([$rolId]);
            $this->db->prepare('DELETE FROM roles WHERE id = ?')->execute([$rolId]);
        }
    }
}
