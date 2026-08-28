<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\RbacService;

class RolController
{
    public function __construct(private RbacService $rbac = new RbacService())
    {
    }

    public function index(Request $request): string
    {
        return View::render('admin/roles', [
            'roles' => $this->rbac->listarRoles(),
            'permisosAgrupados' => $this->rbac->listarPermisosAgrupados(),
        ]);
    }

    public function crear(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $permisoIds = array_map('intval', (array) $request->input('permisos', []));
        $this->rbac->crearRol(
            trim((string) $request->input('nombre')),
            $request->input('descripcion') ?: null,
            $permisoIds
        );

        Session::flash('success', 'Rol creado correctamente.');
        Response::redirect('/admin/roles');
    }

    public function actualizarPermisos(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $rolId = (int) $request->param('id');
        $permisoIds = array_map('intval', (array) $request->input('permisos', []));
        $this->rbac->actualizarPermisosRol($rolId, $permisoIds);

        Session::flash('success', 'Permisos actualizados.');
        Response::redirect('/admin/roles');
    }

    public function eliminar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        try {
            $this->rbac->eliminarRol((int) $request->param('id'));
            Session::flash('success', 'Rol eliminado.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }

        Response::redirect('/admin/roles');
    }
}
