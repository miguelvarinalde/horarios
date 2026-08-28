<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\EmpleadoModel;
use App\Models\HorarioBaseModel;

class HorarioController
{
    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 0 => 'Domingo'];

    public function index(Request $request): string
    {
        $empleadoId = (int) $request->param('empleadoId');
        $empleado = $this->empleadoAutorizadoOAbortar($empleadoId);

        return View::render('horarios/index', [
            'empleado' => $empleado,
            'vigencias' => HorarioBaseModel::porEmpleado($empleadoId),
            'dias' => self::DIAS,
        ]);
    }

    public function crear(Request $request): string
    {
        $empleadoId = (int) $request->param('empleadoId');
        $empleado = $this->empleadoAutorizadoOAbortar($empleadoId);

        return View::render('horarios/form', [
            'empleado' => $empleado,
            'dias' => self::DIAS,
            'vigencia' => null,
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = $request->input('vigente_desde');
        $vigenteHasta = $request->input('vigente_hasta') ?: null;
        $comentario = $request->input('comentario') ?: null;
        $dias = $this->leerDiasDelFormulario($request);

        HorarioBaseModel::crearVigencia($empleadoId, $vigenteDesde, $vigenteHasta, $comentario, $dias);

        Session::flash('success', 'Horario base creado correctamente.');
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }

    public function editarForm(Request $request): string
    {
        $empleadoId = (int) $request->param('empleadoId');
        $empleado = $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = (string) $request->param('vigenteDesde');
        $vigencia = HorarioBaseModel::vigencia($empleadoId, $vigenteDesde);
        if (!$vigencia) {
            Response::abort(404, 'Vigencia de horario no encontrada.');
        }

        return View::render('horarios/form', [
            'empleado' => $empleado,
            'dias' => self::DIAS,
            'vigencia' => $vigencia,
        ]);
    }

    public function actualizar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = (string) $request->param('vigenteDesde');
        if (!HorarioBaseModel::vigencia($empleadoId, $vigenteDesde)) {
            Response::abort(404, 'Vigencia de horario no encontrada.');
        }

        $vigenteHasta = $request->input('vigente_hasta') ?: null;
        $comentario = $request->input('comentario') ?: null;
        $dias = $this->leerDiasDelFormulario($request);

        HorarioBaseModel::actualizarVigencia($empleadoId, $vigenteDesde, $vigenteHasta, $comentario, $dias);

        Session::flash('success', 'Vigencia de horario actualizada.');
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }

    /** @return array<int, array<int, array{hora_inicio:string, hora_fin:string}>> */
    private function leerDiasDelFormulario(Request $request): array
    {
        $dias = [];
        foreach (array_keys(self::DIAS) as $diaSemana) {
            if (!$request->input("dia_{$diaSemana}_activo")) {
                continue;
            }
            $bloques = [];
            foreach ([1, 2] as $n) {
                $inicio = $request->input("dia_{$diaSemana}_bloque{$n}_inicio");
                $fin = $request->input("dia_{$diaSemana}_bloque{$n}_fin");
                if ($inicio && $fin) {
                    $bloques[] = ['hora_inicio' => $inicio, 'hora_fin' => $fin];
                }
            }
            $dias[$diaSemana] = $bloques;
        }
        return $dias;
    }

    public function eliminar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $empleadoId = (int) $request->param('empleadoId');
        $this->empleadoAutorizadoOAbortar($empleadoId);

        $vigenteDesde = $request->input('vigente_desde');

        HorarioBaseModel::eliminarVigencia($empleadoId, $vigenteDesde);

        Session::flash('success', 'Vigencia de horario eliminada.');
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }

    /**
     * Antes este controlador no validaba nada: cualquiera con permiso de
     * horarios.* podia ver/crear/eliminar el horario de CUALQUIER empleado,
     * sin importar su area, con solo cambiar el {empleadoId} de la URL. Se
     * agrega aqui la unica validacion real de autorizacion por fila del
     * controlador: existencia del empleado + (si el usuario no ve todas las
     * areas) que el empleado pertenezca a la misma area.
     */
    private function empleadoAutorizadoOAbortar(int $empleadoId): array
    {
        $empleado = EmpleadoModel::find($empleadoId);
        if (!$empleado) {
            Response::abort(404, 'Empleado no encontrado');
        }

        if (!Auth::veTodasLasAreas()) {
            $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
            $mismaArea = $empleadoPropio && $empleado['area_id'] && (int) $empleado['area_id'] === (int) ($empleadoPropio['area_id'] ?? 0);
            if (!$mismaArea) {
                Response::abort(403, 'No tienes permiso para gestionar el horario de este empleado (no pertenece a tu area).');
            }
        }

        return $empleado;
    }
}
