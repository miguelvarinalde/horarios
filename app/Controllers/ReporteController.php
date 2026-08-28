<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\CalculoDetalleModel;
use App\Models\EmpleadoModel;
use App\Models\PeriodoCalculoModel;
use App\Services\CalculoRecargosService;
use App\Services\ReporteExportService;
use App\Services\ReporteHorasRegistroService;

class ReporteController
{
    /** Orden de despliegue preferido para las columnas de tipo de recargo (no alfabetico). */
    private const ORDEN_CODIGOS_RECARGO = ['ORD', 'RN', 'HED', 'HEN', 'RDF', 'RNDF', 'HEDDF', 'HENDF'];

    private const MESES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function index(Request $request): string
    {
        $periodos = PeriodoCalculoModel::all();

        // Sin periodo_id explicito en la URL: se muestra por defecto el mes
        // calendario actual (buscando o creando ese periodo), en vez de
        // obligar a crear uno manualmente cada vez. Si el usuario SI eligio
        // un periodo puntual (del listado, incluyendo uno quincenal/custom
        // creado a mano), se respeta tal cual.
        $periodoId = (int) $request->query('periodo_id');
        if (!$periodoId) {
            $periodoId = $this->resolverPeriodoDelMesActual();
            $periodos = PeriodoCalculoModel::all(); // puede haberse creado uno nuevo
        }

        $empleadoIdsPermitidos = $this->empleadoIdsPermitidos();

        [$filas, $columnas, $periodo] = $periodoId ? $this->construirResumen($periodoId, $empleadoIdsPermitidos) : [[], [], null];

        return View::render('reportes/horas_extra', [
            'periodos' => $periodos,
            'periodoId' => $periodoId,
            'periodo' => $periodo,
            'filas' => $filas,
            'columnas' => $columnas,
        ]);
    }

    /**
     * Alcance de empleados segun el rol en sesion: quien no tiene el
     * permiso equipos.ver_todas (tipicamente Supervisor) solo ve su propia
     * area (mas el mismo, si no tiene area asignada); Administrador/RRHH/
     * Auditor no tienen restriccion. null = sin restriccion.
     *
     * @return int[]|null
     */
    private function empleadoIdsPermitidos(): ?array
    {
        if (Auth::veTodasLasAreas()) {
            return null;
        }

        $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
        if (!$empleadoPropio) {
            return [];
        }
        if (!$empleadoPropio['area_id']) {
            return [(int) $empleadoPropio['id']];
        }

        $equipo = EmpleadoModel::delArea((int) $empleadoPropio['area_id']);
        return array_map(fn ($e) => (int) $e['id'], $equipo);
    }

    /**
     * Busca (o crea) el periodo de calculo que cubre el mes calendario actual
     * completo. Solo lo CREA si el usuario en sesion tiene permiso de
     * ejecutar el calculo (RRHH/Administrador) — un Supervisor/Auditor que
     * solo puede ver el reporte no debe generar periodos nuevos como efecto
     * secundario de visitar la pantalla; para ellos, si el mes actual aun no
     * tiene periodo, simplemente no hay nada que mostrar todavia.
     */
    private function resolverPeriodoDelMesActual(): int
    {
        $desde = date('Y-m-01');
        $hasta = date('Y-m-t');

        $periodo = PeriodoCalculoModel::porFechas($desde, $hasta);
        if ($periodo) {
            return (int) $periodo['id'];
        }

        if (!Auth::puede('calculo.ejecutar')) {
            return 0;
        }

        $nombre = 'Nomina ' . self::MESES_ES[(int) date('n')] . ' ' . date('Y');
        return PeriodoCalculoModel::insert([
            'nombre' => $nombre,
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'estado' => 'abierto',
        ]);
    }

    public function horasRegistro(Request $request): string
    {
        [$empleados, $empleadoId, $desde, $hasta] = $this->resolverAlcanceHorasRegistro($request);

        $informe = $empleadoId ? (new ReporteHorasRegistroService())->generarInforme($empleadoId, $desde, $hasta) : [];
        [$informe, $columnas, $nombresPorCodigo] = $this->reorganizarPorColumnasDeRecargo($informe);

        return View::render('reportes/horas_registro', [
            'empleados' => $empleados,
            'empleadoId' => $empleadoId,
            'desde' => $desde,
            'hasta' => $hasta,
            'informe' => $informe,
            'columnas' => $columnas,
            'nombresPorCodigo' => $nombresPorCodigo,
        ]);
    }

    public function exportarHorasRegistroExcel(Request $request)
    {
        [, $empleadoId, $desde, $hasta, $empleadoNombre] = $this->resolverAlcanceHorasRegistro($request);

        $informe = $empleadoId ? (new ReporteHorasRegistroService())->generarInforme($empleadoId, $desde, $hasta) : [];
        [$informe, $columnas, $nombresPorCodigo] = $this->reorganizarPorColumnasDeRecargo($informe);

        (new ReporteExportService())->generarExcelHorasRegistro(
            $informe,
            $columnas,
            $nombresPorCodigo,
            $empleadoNombre ?? "empleado_{$empleadoId}",
            $desde,
            $hasta
        );
    }

    /**
     * Resuelve, segun el rol del usuario en sesion, la lista de empleados que puede consultar
     * y el empleado/rango efectivamente solicitado (validando que el empleado_id pedido este
     * dentro de lo permitido). Compartido entre la vista y la exportacion para no duplicar
     * la logica de alcance/seguridad.
     *
     * @return array{0: array, 1: int, 2: string, 3: string, 4: ?string}
     */
    private function resolverAlcanceHorasRegistro(Request $request): array
    {
        $usuario = Auth::usuario();
        $rol = $usuario['rol_nombre'] ?? '';
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        if ($rol === 'Empleado') {
            $empleados = $empleadoPropio ? [$empleadoPropio] : [];
        } elseif (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $empleados = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
        } else {
            $empleados = EmpleadoModel::todosConSupervisor();
        }

        $porId = [];
        foreach ($empleados as $e) {
            $porId[(int) $e['id']] = $e['nombre'];
        }

        $empleadoId = (int) ($request->query('empleado_id') ?: (array_key_first($porId) ?? 0));
        if (!array_key_exists($empleadoId, $porId)) {
            $empleadoId = array_key_first($porId) ?? 0;
        }

        $desde = $request->query('desde') ?: date('Y-m-01');
        $hasta = $request->query('hasta') ?: date('Y-m-d');

        return [$empleados, $empleadoId, $desde, $hasta, $porId[$empleadoId] ?? null];
    }

    /**
     * Convierte el desglose por dia (lista de {codigo,nombre,horas}) del informe de horas
     * segun registro en columnas independientes por tipo de recargo, para que la vista y la
     * exportacion a Excel puedan mostrarlas como columnas fijas en vez de una sola celda de texto.
     *
     * @return array{0: array, 1: string[], 2: array<string,string>}
     */
    private function reorganizarPorColumnasDeRecargo(array $informe): array
    {
        $columnas = [];
        $nombresPorCodigo = [];

        foreach ($informe as &$dia) {
            $dia['recargos'] = [];
            foreach ($dia['desglose'] as $d) {
                $dia['recargos'][$d['codigo']] = $d['horas'];
                $nombresPorCodigo[$d['codigo']] = $d['nombre'];
                if (!in_array($d['codigo'], $columnas, true)) {
                    $columnas[] = $d['codigo'];
                }
            }
        }
        unset($dia);

        $orden = array_flip(self::ORDEN_CODIGOS_RECARGO);
        usort($columnas, fn ($a, $b) => ($orden[$a] ?? 99) <=> ($orden[$b] ?? 99));

        return [$informe, $columnas, $nombresPorCodigo];
    }

    // --- Resumen de nomina segun registro (por empleado, con base en marcaciones reales) ---

    public function nominaRegistro(Request $request): string
    {
        $desde = $request->query('desde') ?: date('Y-m-01');
        $hasta = $request->query('hasta') ?: date('Y-m-t');

        [$filas, $columnas] = $this->construirResumenNominaRegistro($this->empleadosEnAlcanceNominaRegistro(), $desde, $hasta);

        return View::render('reportes/nomina_registro', [
            'desde' => $desde,
            'hasta' => $hasta,
            'filas' => $filas,
            'columnas' => $columnas,
        ]);
    }

    public function exportarNominaRegistroExcel(Request $request)
    {
        $desde = $request->query('desde') ?: date('Y-m-01');
        $hasta = $request->query('hasta') ?: date('Y-m-t');

        [$filas, $columnas] = $this->construirResumenNominaRegistro($this->empleadosEnAlcanceNominaRegistro(), $desde, $hasta);

        (new ReporteExportService())->generarExcelNominaRegistro($filas, $columnas, $desde, $hasta);
    }

    /**
     * Empleados en el alcance del usuario en sesion para este reporte:
     * quien no tiene equipos.ver_todas solo ve su propia area (o solo a si
     * mismo, si no tiene area asignada); RRHH/Administrador/Auditor ven a
     * todos (ya validado por reportes.ver en la ruta).
     */
    private function empleadosEnAlcanceNominaRegistro(): array
    {
        if (Auth::veTodasLasAreas()) {
            return EmpleadoModel::todosConSupervisor();
        }

        $empleadoPropio = EmpleadoModel::porUsuario((int) Auth::id());
        if (!$empleadoPropio) {
            return [];
        }

        return $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
    }

    /**
     * Para cada empleado en el alcance, suma por tipo de recargo el informe
     * dia-por-dia de ReporteHorasRegistroService (el mismo motor que "Horas
     * trabajadas (registro)", basado en marcaciones reales, no en el
     * horario asignado) durante el rango pedido, y cuenta cuantos dias
     * quedaron "incompleto" (sin marcaciones limpias) — un dia incompleto
     * NO suma horas, asi que si hay varios el total puede estar
     * subestimado; se muestra la cuenta para que RRHH lo revise antes de
     * pagar, en vez de que pase desapercibido.
     *
     * @return array{0: array, 1: string[]}
     */
    private function construirResumenNominaRegistro(array $empleados, string $desde, string $hasta): array
    {
        $servicio = new ReporteHorasRegistroService();
        $columnas = [];
        $filas = [];

        foreach ($empleados as $empleado) {
            $informe = $servicio->generarInforme((int) $empleado['id'], $desde, $hasta);

            $recargos = [];
            $diasIncompletos = 0;
            foreach ($informe as $dia) {
                if ($dia['estado'] === 'incompleto') {
                    $diasIncompletos++;
                }
                foreach ($dia['desglose'] as $d) {
                    $recargos[$d['codigo']] = ($recargos[$d['codigo']] ?? 0) + $d['horas'];
                    if (!in_array($d['codigo'], $columnas, true)) {
                        $columnas[] = $d['codigo'];
                    }
                }
            }

            $filas[] = [
                'empleado_id' => (int) $empleado['id'],
                'empleado_nombre' => $empleado['nombre'],
                'recargos' => $recargos,
                'total_horas' => array_sum($recargos),
                'dias_incompletos' => $diasIncompletos,
            ];
        }

        $orden = array_flip(self::ORDEN_CODIGOS_RECARGO);
        usort($columnas, fn ($a, $b) => ($orden[$a] ?? 99) <=> ($orden[$b] ?? 99));

        return [$filas, $columnas];
    }

    public function crearPeriodo(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = PeriodoCalculoModel::insert([
            'nombre' => trim((string) $request->input('nombre')),
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'estado' => 'abierto',
        ]);

        Session::flash('success', 'Periodo de calculo creado.');
        Response::redirect('/reportes/horas-extra?periodo_id=' . $id);
    }

    public function calcular(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $periodoId = (int) $request->input('periodo_id');
        $empleadoIdsPermitidos = $this->empleadoIdsPermitidos();
        (new CalculoRecargosService())->calcularPeriodoTodosLosEmpleados($periodoId, $empleadoIdsPermitidos);

        $mensaje = $empleadoIdsPermitidos === null
            ? 'Calculo ejecutado para todos los empleados activos.'
            : 'Calculo ejecutado para los empleados de tu area.';
        Session::flash('success', $mensaje);
        Response::redirect('/reportes/horas-extra?periodo_id=' . $periodoId);
    }

    public function exportarExcel(Request $request)
    {
        $periodoId = (int) $request->query('periodo_id');
        [$filas, $columnas, $periodo] = $this->construirResumen($periodoId, $this->empleadoIdsPermitidos());
        (new ReporteExportService())->generarExcel($filas, $columnas, $periodo['nombre'] ?? "periodo_{$periodoId}");
    }

    public function exportarPdf(Request $request)
    {
        $periodoId = (int) $request->query('periodo_id');
        [$filas, $columnas, $periodo] = $this->construirResumen($periodoId, $this->empleadoIdsPermitidos());
        (new ReporteExportService())->generarPdf($filas, $columnas, $periodo['nombre'] ?? "periodo_{$periodoId}");
    }

    /**
     * @param int[]|null $empleadoIdsPermitidos ver empleadoIdsPermitidos() — se pasa explicito
     *        (en vez de recalcularlo aqui) para que la vista y la exportacion usen exactamente
     *        el mismo alcance resuelto una sola vez por peticion.
     * @return array{0: array, 1: string[], 2: ?array}
     */
    private function construirResumen(int $periodoId, ?array $empleadoIdsPermitidos): array
    {
        $periodo = PeriodoCalculoModel::find($periodoId);
        $resumen = CalculoDetalleModel::resumenPorPeriodo($periodoId, $empleadoIdsPermitidos);

        $porEmpleado = [];
        $columnas = [];

        foreach ($resumen as $fila) {
            $empleadoId = (int) $fila['empleado_id'];
            $porEmpleado[$empleadoId]['empleado_nombre'] = $fila['empleado_nombre'];
            $porEmpleado[$empleadoId]['recargos'][$fila['codigo']] = (float) $fila['horas'];
            $columnas[$fila['codigo']] = true;
        }

        $columnas = array_keys($columnas);
        $orden = array_flip(self::ORDEN_CODIGOS_RECARGO);
        usort($columnas, fn ($a, $b) => ($orden[$a] ?? 99) <=> ($orden[$b] ?? 99));

        $filas = [];
        foreach ($porEmpleado as $empleadoId => $datos) {
            $total = array_sum($datos['recargos']);
            $filas[] = [
                'empleado_id' => $empleadoId,
                'empleado_nombre' => $datos['empleado_nombre'],
                'recargos' => $datos['recargos'],
                'total_horas' => $total,
            ];
        }

        return [$filas, $columnas, $periodo];
    }
}
