<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\EmpleadoModel;
use App\Models\HorarioBaseModel;
use App\Services\AlcanceAreasService;

class HorarioController
{
    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 0 => 'Domingo'];

    /**
     * Horas continuas de un solo bloque a partir de las cuales se advierte
     * que no tiene ningun descanso interno (2026-09-05, a pedido del
     * usuario tras revisar un horario real de 6h continuas sin almuerzo).
     * Es solo una ADVERTENCIA informativa al guardar, no bloquea nada: la
     * decision final sigue siendo de quien programa el horario. Ver
     * Art. 167 CST — la jornada debe dividirse en secciones con un
     * descanso intermedio para comer, que no se computa dentro de ella.
     */
    private const UMBRAL_BLOQUE_SIN_DESCANSO_HORAS = 6;

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
        $this->flashAdvertenciaBloquesLargos($dias);
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
        $this->flashAdvertenciaBloquesLargos($dias);
        Response::redirect("/empleados/{$empleadoId}/horarios");
    }

    /**
     * Si algun bloque individual (de cualquier dia) supera
     * UMBRAL_BLOQUE_SIN_DESCANSO_HORAS horas continuas, deja una
     * advertencia (no bloqueante) explicando cual dia/bloque y cuantas
     * horas — no reemplaza el flash de exito, se agrega aparte.
     *
     * @param array<int, array<int, array{hora_inicio:string, hora_fin:string}>> $dias
     */
    private function flashAdvertenciaBloquesLargos(array $dias): void
    {
        $avisos = [];
        foreach ($dias as $diaSemana => $bloques) {
            foreach ($bloques as $bloque) {
                $horas = $this->horasEntre($bloque['hora_inicio'], $bloque['hora_fin']);
                if ($horas >= self::UMBRAL_BLOQUE_SIN_DESCANSO_HORAS) {
                    $nombreDia = self::DIAS[$diaSemana] ?? "dia {$diaSemana}";
                    $avisos[] = sprintf(
                        '%s: bloque de %s a %s (%.1f horas continuas, sin ningun descanso interno).',
                        $nombreDia,
                        substr($bloque['hora_inicio'], 0, 5),
                        substr($bloque['hora_fin'], 0, 5),
                        $horas
                    );
                }
            }
        }

        if (empty($avisos)) {
            return;
        }

        $mensaje = "Atencion: hay bloques de " . self::UMBRAL_BLOQUE_SIN_DESCANSO_HORAS . " horas continuas o mas, sin ningun descanso interno (Art. 167 CST exige dividir la jornada con un descanso intermedio para comer). Revisa si corresponde agregar un descanso:\n"
            . implode("\n", $avisos);
        Session::flash('warning', $mensaje);
    }

    /** Igual que en los servicios de calculo: horas entre dos "HH:MM" o "HH:MM:SS". */
    private function horasEntre(string $ini, string $fin): float
    {
        $ini = strlen($ini) === 5 ? $ini . ':00' : $ini;
        $fin = strlen($fin) === 5 ? $fin . ':00' : $fin;
        [$h1, $m1, $s1] = array_map('intval', explode(':', $ini));
        [$h2, $m2, $s2] = array_map('intval', explode(':', $fin));
        return (($h2 * 3600 + $m2 * 60 + $s2) - ($h1 * 3600 + $m1 * 60 + $s1)) / 3600;
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

        $idsPermitidos = AlcanceAreasService::empleadoIdsPermitidos();
        if ($idsPermitidos !== null && !in_array($empleadoId, $idsPermitidos, true)) {
            Response::abort(403, 'No tienes permiso para gestionar el horario de este empleado (no pertenece a tu area).');
        }

        return $empleado;
    }
}
