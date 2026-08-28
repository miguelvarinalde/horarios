<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\EmpleadoModel;
use App\Models\NovedadModel;
use App\Models\TipoNovedadModel;

class NovedadController
{
    private const MAX_DIAS_RANGO = 366;

    public function index(Request $request): string
    {
        $usuario = Auth::usuario();
        $rol = $usuario['rol_nombre'] ?? '';
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        if ($rol === 'Empleado') {
            $novedades = $empleadoPropio ? NovedadModel::deEmpleado((int) $empleadoPropio['id']) : [];
        } elseif (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $equipo = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
            $ids = array_map(fn ($e) => (int) $e['id'], $equipo);
            $novedades = NovedadModel::deEmpleados($ids);
        } else {
            $novedades = NovedadModel::todas();
        }

        return View::render('novedades/index', [
            'novedades' => $novedades,
            'puedeAprobar' => Auth::puede('novedades.aprobar'),
        ]);
    }

    public function crear(Request $request): string
    {
        $usuario = Auth::usuario();
        $rol = $usuario['rol_nombre'] ?? '';
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        // Empleado solo puede crear novedades para si mismo; quien no ve todas las areas
        // solo puede elegir entre los empleados de su propia area; el resto elige entre todos.
        if ($rol === 'Empleado') {
            $empleados = $empleadoPropio ? [$empleadoPropio] : [];
        } elseif (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $empleados = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
        } else {
            $empleados = EmpleadoModel::todosConSupervisor();
        }

        return View::render('novedades/form', [
            'empleados' => $empleados,
            'tipos' => TipoNovedadModel::activos(),
            'empleadoFijo' => $rol === 'Empleado' ? $empleadoPropio : null,
        ]);
    }

    public function guardar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $usuario = Auth::usuario();
        $rol = $usuario['rol_nombre'] ?? '';
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        $empleadoId = ($rol === 'Empleado' && $empleadoPropio)
            ? (int) $empleadoPropio['id']
            : (int) $request->input('empleado_id');

        $fechas = $this->resolverFechas(
            $request->input('fecha') ?: null,
            $request->input('fecha_inicio') ?: null,
            $request->input('fecha_fin') ?: null
        );

        if ($fechas === null) {
            Response::redirect('/novedades/crear');
            return;
        }

        $datosComunes = [
            'empleado_id' => $empleadoId,
            'tipo_novedad_id' => (int) $request->input('tipo_novedad_id'),
            'hora_inicio' => $request->input('hora_inicio') ?: null,
            'hora_fin' => $request->input('hora_fin') ?: null,
            'comentario' => $request->input('comentario') ?: null,
            'estado' => 'pendiente',
            'creado_por' => (int) $usuario['id'],
        ];

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($fechas as $fecha) {
                NovedadModel::insert(['fecha' => $fecha] + $datosComunes);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Session::flash('error', 'No fue posible registrar la novedad: ' . $e->getMessage());
            Response::redirect('/novedades/crear');
            return;
        }

        $mensaje = count($fechas) > 1
            ? 'Se registraron ' . count($fechas) . ' novedades (una por cada dia del rango), pendientes de aprobacion.'
            : 'Novedad registrada. Queda pendiente de aprobacion.';
        Session::flash('success', $mensaje);
        Response::redirect('/novedades');
    }

    /**
     * Resuelve la lista de fechas (formato Y-m-d) a registrar a partir de una fecha unica
     * o un rango "desde"/"hasta". Devuelve null (y deja un flash de error) si la entrada
     * no es valida.
     *
     * @return string[]|null
     */
    private function resolverFechas(?string $fechaUnica, ?string $fechaInicio, ?string $fechaFin): ?array
    {
        if ($fechaInicio && $fechaFin) {
            try {
                $inicio = new \DateTimeImmutable($fechaInicio);
                $fin = new \DateTimeImmutable($fechaFin);
            } catch (\Exception $e) {
                Session::flash('error', 'Las fechas del rango no son validas.');
                return null;
            }

            if ($fin < $inicio) {
                Session::flash('error', 'La fecha "Hasta" no puede ser anterior a la fecha "Desde".');
                return null;
            }

            $dias = (int) $inicio->diff($fin)->days + 1;
            if ($dias > self::MAX_DIAS_RANGO) {
                Session::flash('error', 'El rango no puede superar ' . self::MAX_DIAS_RANGO . ' dias.');
                return null;
            }

            $periodo = new \DatePeriod($inicio, new \DateInterval('P1D'), $fin->modify('+1 day'));
            $fechas = [];
            foreach ($periodo as $dia) {
                $fechas[] = $dia->format('Y-m-d');
            }
            return $fechas;
        }

        if ($fechaUnica) {
            return [$fechaUnica];
        }

        Session::flash('error', 'Debes indicar una fecha o un rango de fechas.');
        return null;
    }

    public function aprobar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }
        $id = (int) $request->param('id');
        if (!$this->puedeGestionarNovedad($id)) {
            Response::abort(403, 'No tienes permiso para aprobar esta novedad (no pertenece a tu area).');
            return;
        }
        NovedadModel::aprobar($id, (int) Auth::id());
        Session::flash('success', 'Novedad aprobada.');
        Response::redirect('/novedades');
    }

    public function rechazar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }
        $id = (int) $request->param('id');
        if (!$this->puedeGestionarNovedad($id)) {
            Response::abort(403, 'No tienes permiso para rechazar esta novedad (no pertenece a tu area).');
            return;
        }
        NovedadModel::rechazar($id, (int) Auth::id());
        Session::flash('success', 'Novedad rechazada.');
        Response::redirect('/novedades');
    }

    /**
     * Verifica que la novedad exista y, si el usuario en sesion no ve todas
     * las areas, que pertenezca a un empleado de su propia area. Sin esto,
     * cualquiera con novedades.aprobar podia aprobar/rechazar la novedad de
     * CUALQUIER empleado adivinando el id, sin importar el equipo — la
     * pantalla de listado ya filtraba, pero estas acciones no validaban
     * nada por su cuenta.
     */
    private function puedeGestionarNovedad(int $novedadId): bool
    {
        $novedad = NovedadModel::conDetalle($novedadId);
        if (!$novedad) {
            return false;
        }
        if (Auth::veTodasLasAreas()) {
            return true;
        }

        $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
        if (!$empleadoPropio || !$empleadoPropio['area_id']) {
            return false;
        }

        return (int) $novedad['area_id'] === (int) $empleadoPropio['area_id'];
    }
}
