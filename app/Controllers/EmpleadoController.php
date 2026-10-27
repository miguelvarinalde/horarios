<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\AreaModel;
use App\Models\EmpleadoModel;
use App\Models\HorarioBaseModel;
use App\Models\SupervisorAreaModel;
use App\Models\UsuarioModel;
use App\Services\AlcanceAreasService;
use App\Services\CalendarioMs365Service;
use App\Services\PoliticaPasswordService;
use App\Services\PrivilegiosService;

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
            // Solo los roles que el usuario en sesion puede conceder (no puede
            // crear una cuenta con mas permisos de los que el mismo tiene).
            'roles' => PrivilegiosService::rolesAsignables(Auth::permisos()),
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        // Todo se valida ANTES de crear nada, para no dejar un empleado a
        // medias si el rol pedido no se puede conceder.
        $areaId = $request->input('area_id') ?: null;
        if ($areaId !== null && !AlcanceAreasService::areaPermitida((int) $areaId)) {
            Response::abort(403, 'No puedes crear empleados en un area fuera de tu alcance.');
            return;
        }

        $crearUsuario = $request->input('crear_usuario');
        $conCuenta = $crearUsuario && $request->input('email');
        $rolId = (int) $request->input('rol_id');
        if ($conCuenta && !Auth::puedeAsignarRol($rolId)) {
            Session::flash('error', 'No puedes crear una cuenta con ese rol: incluye permisos que tu rol no tiene. No se creo el empleado.');
            Response::redirect('/empleados/crear');
            return;
        }

        $passwordManual = trim((string) $request->input('password', ''));
        if ($conCuenta && $passwordManual !== '') {
            $problema = PoliticaPasswordService::validar($passwordManual, trim((string) $request->input('email')));
            if ($problema !== null) {
                Session::flash('error', $problema . ' No se creo el empleado.');
                Response::redirect('/empleados/crear');
                return;
            }
        }

        $empleadoId = EmpleadoModel::insert([
            'nombre' => trim((string) $request->input('nombre')),
            'documento' => trim((string) $request->input('documento')),
            'cargo' => $request->input('cargo') ?: null,
            'fecha_ingreso' => $request->input('fecha_ingreso'),
            'supervisor_id' => $request->input('supervisor_id') ?: null,
            'area_id' => $areaId,
            'activo' => 1,
        ]);

        // Opcionalmente, crear cuenta de usuario para el empleado.
        if ($conCuenta) {
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
                'rol_id' => $rolId,
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
        $empleado = AlcanceAreasService::autorizarEmpleadoOAbortar($id);

        $usuarioVinculado = $empleado['usuario_id'] ? UsuarioModel::find((int) $empleado['usuario_id']) : null;

        return View::render('empleados/form', [
            'empleado' => $empleado,
            'supervisores' => array_filter(EmpleadoModel::posiblesSupervisores(), fn ($s) => $s['id'] != $id),
            'areas' => AreaModel::activas(),
            'roles' => PrivilegiosService::rolesAsignables(Auth::permisos()),
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
        $empleado = AlcanceAreasService::autorizarEmpleadoOAbortar($id);

        $areaId = $request->input('area_id') ?: null;
        if ($areaId !== null && !AlcanceAreasService::areaPermitida((int) $areaId)) {
            Response::abort(403, 'No puedes mover a un empleado a un area fuera de tu alcance.');
            return;
        }

        $sincronizarCalendarioAnterior = (bool) ($empleado['sincronizar_calendario'] ?? false);
        $sincronizarCalendarioNuevo = (bool) $request->input('sincronizar_calendario');

        $usuarioId = $empleado['usuario_id'] ? (int) $empleado['usuario_id'] : null;
        $avisos = [];

        $activoSolicitado = $request->input('activo') ? 1 : 0;
        $activoNuevo = $activoSolicitado;

        // La cuenta de acceso vinculada solo se modifica (estado y correo) si
        // quien edita ya tiene todos los permisos de esa cuenta: de lo
        // contrario RRHH podria dar de baja o redirigir el correo de un
        // Administrador (ver PrivilegiosService).
        $puedeSobreCuenta = $usuarioId !== null && Auth::puedeGestionarUsuario($usuarioId);
        if ($usuarioId !== null) {
            if (!$puedeSobreCuenta) {
                $activoNuevo = (int) $empleado['activo'];
                if ($activoSolicitado !== $activoNuevo) {
                    $avisos[] = 'No se cambio el estado activo: la cuenta de este empleado tiene permisos que tu rol no tiene.';
                }
            } elseif ($usuarioId === (int) Auth::id() && $activoNuevo === 0) {
                $activoNuevo = 1;
                $avisos[] = 'No puedes desactivar tu propia cuenta; el empleado se mantuvo activo.';
            }
        }

        EmpleadoModel::update($id, [
            'nombre' => trim((string) $request->input('nombre')),
            'documento' => trim((string) $request->input('documento')),
            'cargo' => $request->input('cargo') ?: null,
            'fecha_ingreso' => $request->input('fecha_ingreso'),
            'supervisor_id' => $request->input('supervisor_id') ?: null,
            'area_id' => $areaId,
            'activo' => $activoNuevo,
            'sincronizar_calendario' => $sincronizarCalendarioNuevo ? 1 : 0,
        ]);

        if ($usuarioId !== null) {
            $usuarioActual = UsuarioModel::find($usuarioId);

            // La baja (o reactivacion) del empleado se refleja en su cuenta:
            // antes solo cambiaba empleados.activo y el usuario seguia
            // entrando. Auth::check() revalida usuarios.activo en cada
            // peticion, asi que tambien cierra sus sesiones abiertas.
            if ($puedeSobreCuenta && $usuarioActual && (int) $usuarioActual['activo'] !== $activoNuevo) {
                UsuarioModel::update($usuarioId, ['activo' => $activoNuevo]);
            }

            // Areas adicionales que puede supervisar (2026-09-05, ademas de
            // su propia area de arriba). Quien no ve todas las areas solo
            // puede asignar/quitar areas DENTRO de su propio alcance: las
            // que ya tuviera la cuenta fuera de ese alcance se conservan, y
            // nadie puede ampliar su alcance (ni el de otro) mas alla del suyo.
            $areaIds = array_map('intval', (array) $request->input('areas_adicionales', []));
            $areasPermitidas = AlcanceAreasService::areaIdsPermitidos();
            if ($areasPermitidas !== null) {
                $conservadas = array_diff(SupervisorAreaModel::areaIdsDe($usuarioId), $areasPermitidas);
                $areaIds = array_values(array_unique(array_merge($conservadas, array_intersect($areaIds, $areasPermitidas))));
            }
            SupervisorAreaModel::sincronizar($usuarioId, $areaIds);

            // Correo de la cuenta de acceso (2026-09-05, antes no habia
            // forma de corregirlo desde esta pantalla si el empleado
            // cambiaba de correo o se habia digitado mal al crearlo).
            $emailActual = $usuarioActual['email'] ?? '';
            $nuevoEmail = trim((string) $request->input('email', ''));
            if (!$puedeSobreCuenta && $nuevoEmail !== '' && $nuevoEmail !== $emailActual) {
                // El SSO de Microsoft 365 vincula la cuenta por correo: poder
                // cambiarlo equivale a poder entrar como esa cuenta.
                $avisos[] = 'No se cambio el correo: la cuenta de este empleado tiene permisos que tu rol no tiene.';
                $nuevoEmail = '';
            }
            $emailCambio = $nuevoEmail !== '' && $nuevoEmail !== $emailActual;

            if ($emailCambio && $sincronizarCalendarioAnterior) {
                // El calendario ya estaba sincronizado contra el correo
                // (buzon) ANTERIOR: hay que limpiar sus eventos antes de
                // que el correo apunte a otro buzon, o quedarian eventos
                // huerfanos que la app ya no puede administrar.
                try {
                    (new CalendarioMs365Service())->eliminarTodosLosEventosDelEmpleado($id);
                } catch (\Throwable $e) {
                }
            }

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

            if ($sincronizarCalendarioNuevo && (!$sincronizarCalendarioAnterior || $emailCambio)) {
                try {
                    $servicio = new CalendarioMs365Service();
                    foreach (HorarioBaseModel::vigenciasVigentesHoy($id) as $vigenteDesde) {
                        $servicio->sincronizarVigencia($id, $vigenteDesde);
                    }
                } catch (\Throwable $e) {
                }
            } elseif (!$sincronizarCalendarioNuevo && $sincronizarCalendarioAnterior) {
                try {
                    (new CalendarioMs365Service())->eliminarTodosLosEventosDelEmpleado($id);
                } catch (\Throwable $e) {
                }
            }
        }

        if ($avisos) {
            Session::flash('error', implode(' ', $avisos));
        }
        Session::flash('success', 'Empleado actualizado correctamente.');
        Response::redirect('/empleados');
    }
}
