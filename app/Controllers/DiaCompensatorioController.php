<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\DiaCompensatorioModel;
use App\Models\EmpleadoModel;

class DiaCompensatorioController
{
    private const TRATAMIENTOS_VALIDOS = ['recargo', 'descanso_compensatorio', 'ambos'];

    public function index(Request $request): string
    {
        $usuario = Auth::usuario();
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        $empleadoIds = null;
        if (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $equipo = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
            $empleadoIds = array_map(fn ($e) => (int) $e['id'], $equipo);
        }

        $desde = $request->query('desde') ?: null;
        $hasta = $request->query('hasta') ?: null;

        return View::render('dias_compensatorios/index', [
            'dias' => DiaCompensatorioModel::listarConFiltros($empleadoIds, $desde, $hasta),
            'desde' => $desde,
            'hasta' => $hasta,
            'puedeGestionar' => Auth::puede('dias_compensatorios.gestionar'),
        ]);
    }

    public function actualizarTratamiento(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $dia = DiaCompensatorioModel::find($id);

        if (!$dia) {
            Response::abort(404, 'Registro no encontrado.');
            return;
        }

        if ($dia['clasificacion'] === 'habitual') {
            Session::flash('error', 'Este dia es de trabajo dominical/festivo habitual (3 o mas en el mes): la ley da derecho a recargo Y descanso compensatorio, no es una eleccion.');
            Response::redirect('/dias-compensatorios');
            return;
        }

        $tratamiento = $request->input('tratamiento');
        if (!in_array($tratamiento, ['recargo', 'descanso_compensatorio'], true)) {
            Session::flash('error', 'Para un dia ocasional solo se puede elegir "recargo" o "descanso compensatorio".');
            Response::redirect('/dias-compensatorios');
            return;
        }

        DiaCompensatorioModel::actualizarTratamiento($id, $tratamiento, $request->input('comentario') ?: null);

        Session::flash('success', 'Eleccion registrada. Recalcula el periodo correspondiente para que se refleje en el reporte de horas extra.');
        Response::redirect('/dias-compensatorios');
    }

    public function marcarDescansoTomado(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $fecha = $request->input('descanso_tomado_fecha');

        if (!$fecha) {
            Session::flash('error', 'Selecciona la fecha en que se tomo el descanso.');
            Response::redirect('/dias-compensatorios');
            return;
        }

        DiaCompensatorioModel::marcarDescansoTomado($id, $fecha);

        Session::flash('success', 'Descanso compensatorio marcado como tomado.');
        Response::redirect('/dias-compensatorios');
    }
}
