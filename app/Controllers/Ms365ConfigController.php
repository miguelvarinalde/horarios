<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ConfiguracionMs365Model;

class Ms365ConfigController
{
    public function mostrar(Request $request): string
    {
        return View::render('admin/ms365', [
            'config' => ConfiguracionMs365Model::obtener(),
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        ConfiguracionMs365Model::guardar(
            trim((string) $request->input('tenant_id')),
            trim((string) $request->input('client_id')),
            $request->input('client_secret') !== '' ? trim((string) $request->input('client_secret')) : null,
            trim((string) $request->input('redirect_uri'))
        );

        Session::flash('success', 'Configuracion de Microsoft 365 guardada.');
        Response::redirect('/admin/ms365');
    }
}
