<?php
// app/Controllers/CalendarioMs365ConfigController.php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ConfiguracionCalendarioMs365Model;
use App\Models\EventoCalendarioMs365Model;
use App\Services\CalendarioMs365Service;

class CalendarioMs365ConfigController
{
    public function mostrar(Request $request): string
    {
        return View::render('admin/calendario_ms365', [
            'config' => ConfiguracionCalendarioMs365Model::obtener(),
            'errores' => EventoCalendarioMs365Model::conError(),
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        ConfiguracionCalendarioMs365Model::guardar(
            trim((string) $request->input('tenant_id')),
            trim((string) $request->input('client_id')),
            $request->input('client_secret') !== '' ? trim((string) $request->input('client_secret')) : null,
            (bool) $request->input('activo')
        );

        Session::flash('success', 'Configuracion de calendario Microsoft 365 guardada.');
        Response::redirect('/admin/calendario-ms365');
    }

    public function reintentar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        (new CalendarioMs365Service())->reintentar($id);

        Session::flash('success', 'Se reintento la sincronizacion.');
        Response::redirect('/admin/calendario-ms365');
    }
}
