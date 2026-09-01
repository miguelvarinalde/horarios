<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\EmpleadoModel;
use App\Models\RegistroTiempoModel;
use App\Models\UsuarioModel;
use DateTime;
use Exception;

class RegistroTiempoController
{
    private const ESTADOS_UBICACION_VALIDOS = ['capturada', 'denegada', 'no_disponible', 'tiempo_agotado', 'no_soportado'];

    /**
     * Decide si la proxima marcacion debe ser "entrada" o "salida" a partir
     * de la ultima marcacion registrada. Si la ultima fue una entrada de un
     * dia ANTERIOR (se le olvido marcar salida ese dia), NO se arrastra el
     * problema al dia siguiente: se vuelve a ofrecer "entrada" para
     * empezar el nuevo dia limpio, en vez de forzar una "salida" que en
     * realidad corresponderia al dia de ayer. El informe "Horas trabajadas
     * (registro)" ya resuelve, aparte, que hora de salida asumir para ese
     * dia anterior incompleto (ver ReporteHorasRegistroService) — esto
     * solo evita que la confusion se propague hacia adelante en la
     * pantalla de auto-marcacion.
     */
    private function siguienteTipoMarcacion(?array $ultimo): string
    {
        if (!$ultimo || $ultimo['tipo'] === 'salida') {
            return 'entrada';
        }

        $fechaUltimo = substr($ultimo['fecha_hora'], 0, 10);
        return $fechaUltimo === date('Y-m-d') ? 'salida' : 'entrada';
    }

    /** Pantalla de auto-marcacion: consentimiento (primera vez) + boton + historial propio. */
    public function marcar(Request $request): string
    {
        $usuario = Auth::usuario();
        $empleado = EmpleadoModel::porUsuario((int) $usuario['id']);

        $ultimo = $empleado ? RegistroTiempoModel::ultimo((int) $empleado['id']) : null;
        $siguienteTipo = $this->siguienteTipoMarcacion($ultimo);

        return View::render('registros_tiempo/marcar', [
            'empleado' => $empleado,
            'necesitaConsentimiento' => empty($usuario['consentimiento_ubicacion_at']),
            'siguienteTipo' => $siguienteTipo,
            'historial' => $empleado ? RegistroTiempoModel::historialEmpleado((int) $empleado['id'], 10) : [],
        ]);
    }

    public function aceptarConsentimiento(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        UsuarioModel::registrarConsentimientoUbicacion((int) Auth::id());
        Response::redirect('/registros-tiempo/marcar');
    }

    public function registrar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $usuario = Auth::usuario();
        $empleado = EmpleadoModel::porUsuario((int) $usuario['id']);

        if (!$empleado) {
            Session::flash('error', 'Tu usuario no tiene un empleado asociado; no es posible marcar entrada/salida.');
            Response::redirect('/registros-tiempo/marcar');
            return;
        }

        if (empty($usuario['consentimiento_ubicacion_at'])) {
            Session::flash('error', 'Debes aceptar el uso de tu ubicacion antes de marcar.');
            Response::redirect('/registros-tiempo/marcar');
            return;
        }

        // El tipo (entrada/salida) lo decide el SERVIDOR a partir del ultimo
        // registro, no el cliente, para que no se pueda enviar dos entradas
        // seguidas manipulando el formulario.
        $ultimo = RegistroTiempoModel::ultimo((int) $empleado['id']);
        $tipo = $this->siguienteTipoMarcacion($ultimo);

        $estadoUbicacion = $request->input('ubicacion_estado');
        if (!in_array($estadoUbicacion, self::ESTADOS_UBICACION_VALIDOS, true)) {
            $estadoUbicacion = 'no_soportado';
        }

        $capturada = $estadoUbicacion === 'capturada';
        $lat = $capturada ? $request->input('lat') : null;
        $lon = $capturada ? $request->input('lon') : null;
        $precision = $capturada ? $request->input('precision') : null;

        $fechaHoraCliente = $request->input('fecha_hora_cliente') ?: null;

        // El campo de observaciones solo se muestra en el formulario cuando
        // la marcacion es de salida (ver registros_tiempo/marcar.php); si
        // llega en una entrada se ignora, para que el campo del formulario
        // sea la unica fuente valida de esta columna.
        $comentario = $tipo === 'salida' ? trim((string) $request->input('comentario', '')) : '';

        RegistroTiempoModel::insert([
            'empleado_id' => $empleado['id'],
            'tipo' => $tipo,
            'fecha_hora' => date('Y-m-d H:i:s'),
            'fecha_hora_cliente' => $fechaHoraCliente,
            'latitud' => ($lat !== null && $lat !== '') ? $lat : null,
            'longitud' => ($lon !== null && $lon !== '') ? $lon : null,
            'precision_metros' => ($precision !== null && $precision !== '') ? $precision : null,
            'ubicacion_estado' => $estadoUbicacion,
            'ip_address' => $request->server('REMOTE_ADDR'),
            'user_agent' => substr((string) $request->server('HTTP_USER_AGENT', ''), 0, 255),
            'comentario' => $comentario !== '' ? $comentario : null,
        ]);

        $mensaje = $tipo === 'entrada' ? 'Entrada registrada.' : 'Salida registrada.';
        if (!$capturada) {
            $mensaje .= ' No fue posible capturar tu ubicacion (' . $estadoUbicacion . ').';
        }
        Session::flash('success', $mensaje);
        Response::redirect('/registros-tiempo/marcar');
    }

    /** Listado para RRHH/Supervisor/Auditor. */
    public function index(Request $request): string
    {
        $usuario = Auth::usuario();
        $empleadoPropio = EmpleadoModel::porUsuario((int) $usuario['id']);

        $empleadoIds = null; // null = sin restriccion (ve todos)
        if (!Auth::veTodasLasAreas() && $empleadoPropio) {
            $equipo = $empleadoPropio['area_id'] ? EmpleadoModel::delArea((int) $empleadoPropio['area_id']) : [$empleadoPropio];
            $empleadoIds = array_map(fn ($e) => (int) $e['id'], $equipo);
        }

        $desde = $request->query('desde') ?: null;
        $hasta = $request->query('hasta') ?: null;

        return View::render('registros_tiempo/index', [
            'registros' => RegistroTiempoModel::listarConFiltros($empleadoIds, $desde, $hasta),
            'desde' => $desde,
            'hasta' => $hasta,
        ]);
    }

    // --- Correccion manual (RRHH/Administrador): agregar, editar o eliminar una marcacion puntual ---

    public function crearForm(Request $request): string
    {
        return View::render('registros_tiempo/form', [
            'registro' => null,
            'empleados' => EmpleadoModel::todosConSupervisor(),
        ]);
    }

    public function crear(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        [$fechaHora, $error] = $this->combinarFechaHora($request->input('fecha'), $request->input('hora'));
        $comentario = trim((string) $request->input('comentario'));

        if (!$error && $comentario === '') {
            $error = 'Debes explicar el motivo de la marcacion agregada manualmente (queda como observacion en el historial).';
        }

        if ($error) {
            Session::flash('error', $error);
            Response::redirect('/registros-tiempo/crear');
            return;
        }

        RegistroTiempoModel::insert([
            'empleado_id' => (int) $request->input('empleado_id'),
            'tipo' => $request->input('tipo') === 'salida' ? 'salida' : 'entrada',
            'fecha_hora' => $fechaHora,
            'fecha_hora_cliente' => null,
            'latitud' => null,
            'longitud' => null,
            'precision_metros' => null,
            'ubicacion_estado' => 'no_disponible',
            'ip_address' => $request->server('REMOTE_ADDR'),
            'user_agent' => substr((string) $request->server('HTTP_USER_AGENT', ''), 0, 255),
            'comentario' => $comentario,
        ]);

        Session::flash('success', 'Marcacion agregada.');
        Response::redirect('/registros-tiempo');
    }

    public function editarForm(Request $request): string
    {
        $registro = RegistroTiempoModel::conEmpleado((int) $request->param('id'));
        if (!$registro) {
            Response::abort(404, 'Marcacion no encontrada.');
        }

        return View::render('registros_tiempo/form', [
            'registro' => $registro,
            'empleados' => [],
        ]);
    }

    public function editar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        if (!RegistroTiempoModel::find($id)) {
            Response::abort(404, 'Marcacion no encontrada.');
        }

        [$fechaHora, $error] = $this->combinarFechaHora($request->input('fecha'), $request->input('hora'));
        $comentario = trim((string) $request->input('comentario'));

        if (!$error && $comentario === '') {
            $error = 'Debes explicar el motivo de la correccion (queda como observacion en el historial).';
        }

        if ($error) {
            Session::flash('error', $error);
            Response::redirect("/registros-tiempo/{$id}/editar");
            return;
        }

        RegistroTiempoModel::update($id, [
            'tipo' => $request->input('tipo') === 'salida' ? 'salida' : 'entrada',
            'fecha_hora' => $fechaHora,
            'comentario' => $comentario,
        ]);

        Session::flash('success', 'Marcacion corregida.');
        Response::redirect('/registros-tiempo');
    }

    public function eliminar(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        RegistroTiempoModel::delete((int) $request->param('id'));

        Session::flash('success', 'Marcacion eliminada.');
        Response::redirect('/registros-tiempo');
    }

    /** @return array{0: ?string, 1: ?string} [fecha_hora en Y-m-d H:i:s, mensaje de error] */
    private function combinarFechaHora(?string $fecha, ?string $hora): array
    {
        if (!$fecha || !$hora) {
            return [null, 'Debes indicar fecha y hora.'];
        }

        try {
            $dt = new DateTime("{$fecha} {$hora}");
        } catch (Exception $e) {
            return [null, 'Fecha u hora invalida.'];
        }

        return [$dt->format('Y-m-d H:i:s'), null];
    }
}
