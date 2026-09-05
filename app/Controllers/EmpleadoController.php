<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\AreaModel;
use App\Models\EmpleadoModel;
use App\Models\RolModel;
use App\Models\SupervisorAreaModel;
use App\Models\UsuarioModel;
use App\Services\AlcanceAreasService;

class EmpleadoController
{
    public function index(Request $request): string
    {
        $empleados = AlcanceAreasService::empleadosPermitidos();

        return View::render('empleados/index', [
            'empleados' => $empleados,
            'passwordGenerada' => Session::getFlash('password_generada'),
        ]);
    }

    public function crear(Request $request): string
    {
        return View::render('empleados/form', [
            'empleado' => null,
            'supervisores' => EmpleadoModel::posiblesSupervisores(),
            'areas' => AreaModel::activas(),
            'roles' => RolModel::all('nombre ASC'),
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = EmpleadoModel::insert([
            'nombre' => trim((string) $request->input('nombre')),
            'documento' => trim((string) $request->input('documento')),
            'cargo' => $request->input('cargo') ?: null,
            'fecha_ingreso' => $request->input('fecha_ingreso'),
            'supervisor_id' => $request->input('supervisor_id') ?: null,
            'area_id' => $request->input('area_id') ?: null,
            'activo' => 1,
        ]);

        // Opcionalmente, crear cuenta de usuario para el empleado.
        $crearUsuario = $request->input('crear_usuario');
        if ($crearUsuario && $request->input('email')) {
            $passwordIngresada = trim((string) $request->input('password', ''));
            $password = $passwordIngresada !== '' ? $passwordIngresada : bin2hex(random_bytes(6));

            $usuarioId = UsuarioModel::insert([
                'nombre' => trim((string) $request->input('nombre')),
                'email' => trim((string) $request->input('email')),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                // Ni el Administrador ni RRHH deberian conocer la contrasena
                // real del usuario a largo plazo: se le obliga a cambiarla
                // en su primer login (ver AuthMiddleware).
                'debe_cambiar_password' => 1,
                'rol_id' => (int) $request->input('rol_id'),
                'activo' => 1,
            ]);
            \App\Models\EmpleadoModel::update($empleadoId, ['usuario_id' => $usuarioId]);

            // Se muestra UNA sola vez, en la siguiente pantalla: es la unica
            // oportunidad de copiarla para entregarsela al empleado.
            Session::flash('password_generada', [
                'usuario' => trim((string) $request->input('nombre')),
                'email' => trim((string) $request->input('email')),
                'password' => $password,
            ]);
        }

        Session::flash('success', 'Empleado creado correctamente.');
        Response::redirect('/empleados');
    }

    public function editar(Request $request): string
    {
        $id = (int) $request->param('id');
        $empleado = EmpleadoModel::find($id);
        if (!$empleado) {
            Response::abort(404, 'Empleado no encontrado');
        }

        $usuarioVinculado = $empleado['usuario_id'] ? UsuarioModel::find((int) $empleado['usuario_id']) : null;

        return View::render('empleados/form', [
            'empleado' => $empleado,
            'supervisores' => array_filter(EmpleadoModel::posiblesSupervisores(), fn ($s) => $s['id'] != $id),
            'areas' => AreaModel::activas(),
            'roles' => RolModel::all('nombre ASC'),
            'areaIdsAdicionales' => $usuarioVinculado ? SupervisorAreaModel::areaIdsDe((int) $usuarioVinculado['id']) : [],
            'usuarioEmail' => $usuarioVinculado['email'] ?? null,
        ]);
    }

    public function actualizar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $empleado = EmpleadoModel::find($id);

        EmpleadoModel::update($id, [
            'nombre' => trim((string) $request->input('nombre')),
            'documento' => trim((string) $request->input('documento')),
            'cargo' => $request->input('cargo') ?: null,
            'fecha_ingreso' => $request->input('fecha_ingreso'),
            'supervisor_id' => $request->input('supervisor_id') ?: null,
            'area_id' => $request->input('area_id') ?: null,
            'activo' => $request->input('activo') ? 1 : 0,
        ]);

        if ($empleado && $empleado['usuario_id']) {
            $usuarioId = (int) $empleado['usuario_id'];

            // Areas adicionales que puede supervisar (2026-09-05, ademas de
            // su propia area de arriba).
            $areaIds = array_map('intval', (array) $request->input('areas_adicionales', []));
            SupervisorAreaModel::sincronizar($usuarioId, $areaIds);

            // Correo de la cuenta de acceso (2026-09-05, antes no habia
            // forma de corregirlo desde esta pantalla si el empleado
            // cambiaba de correo o se habia digitado mal al crearlo).
            $nuevoEmail = trim((string) $request->input('email', ''));
            if ($nuevoEmail !== '') {
                try {
                    UsuarioModel::update($usuarioId, ['email' => $nuevoEmail]);
                } catch (\PDOException $e) {
                    // Codigo 23000 = violacion de restriccion unica (el
                    // correo ya esta en uso por otra cuenta).
                    Session::flash('error', 'El empleado se actualizo, pero el correo no se pudo cambiar: "' . $nuevoEmail . '" ya esta en uso por otra cuenta.');
                    Response::redirect("/empleados/{$id}/editar");
                    return;
                }
            }
        }

        Session::flash('success', 'Empleado actualizado correctamente.');
        Response::redirect('/empleados');
    }
}
