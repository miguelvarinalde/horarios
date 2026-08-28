<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\AreaModel;
use App\Models\ConfiguracionGlobalModel;
use App\Models\ConfiguracionSitioModel;
use App\Models\FestivoModel;
use App\Models\PeriodoNoLaborableModel;
use App\Models\TipoNovedadModel;
use App\Models\TipoRecargoModel;
use App\Services\FestivosService;

class AdminConfigController
{
    // --- Areas / equipos (definen el alcance de "equipo" para Supervisor y similares) ---

    public function mostrarAreas(Request $request): string
    {
        return View::render('admin/areas', [
            'areas' => AreaModel::all(),
        ]);
    }

    public function guardarArea(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $nombre = trim((string) $request->input('nombre'));
        if ($nombre === '') {
            Session::flash('error', 'El nombre del area no puede estar vacio.');
            Response::redirect('/admin/areas');
            return;
        }

        try {
            AreaModel::insert(['nombre' => $nombre, 'activo' => 1]);
            Session::flash('success', 'Area creada.');
        } catch (\Throwable $e) {
            Session::flash('error', 'Ya existe un area con ese nombre.');
        }

        Response::redirect('/admin/areas');
    }

    public function alternarArea(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $area = AreaModel::find($id);
        if ($area) {
            AreaModel::update($id, ['activo' => $area['activo'] ? 0 : 1]);
        }

        Response::redirect('/admin/areas');
    }

    // Extensiones permitidas por MIME real (getimagesize, no por lo que diga el nombre del archivo).
    private const MIME_A_EXTENSION_LOGO = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    private const LOGO_MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    // --- Personalizacion del sitio (nombre, logo, pie de pagina) ---

    public function mostrarSitio(Request $request): string
    {
        return View::render('admin/sitio', [
            'sitio' => ConfiguracionSitioModel::obtener(),
        ]);
    }

    public function guardarSitioTexto(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $nombre = trim((string) $request->input('nombre_aplicacion'));
        ConfiguracionSitioModel::guardarTexto(
            $nombre !== '' ? $nombre : 'Gestion de Horarios',
            trim((string) $request->input('footer_texto')) ?: null
        );

        Session::flash('success', 'Personalizacion del sitio guardada.');
        Response::redirect('/admin/sitio');
    }

    public function subirLogo(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $archivo = $_FILES['logo'] ?? null;

        if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'No se selecciono ningun archivo.');
            Response::redirect('/admin/sitio');
            return;
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'No fue posible subir el archivo (error al transferirlo).');
            Response::redirect('/admin/sitio');
            return;
        }

        if ($archivo['size'] > self::LOGO_MAX_BYTES) {
            Session::flash('error', 'El logo no puede superar 2 MB.');
            Response::redirect('/admin/sitio');
            return;
        }

        // Se valida el contenido real de la imagen (no la extension ni el
        // Content-Type que declare el navegador, que se pueden falsificar).
        $info = @getimagesize($archivo['tmp_name']);
        $extension = $info['mime'] ?? null ? (self::MIME_A_EXTENSION_LOGO[$info['mime']] ?? null) : null;

        if (!$info || !$extension) {
            Session::flash('error', 'El archivo no es una imagen valida (se acepta PNG, JPG, GIF o WEBP).');
            Response::redirect('/admin/sitio');
            return;
        }

        $directorio = dirname(__DIR__, 2) . '/public/uploads/logo';
        if (!is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

        $nombreArchivo = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($archivo['tmp_name'], $directorio . '/' . $nombreArchivo)) {
            Session::flash('error', 'No fue posible guardar el archivo en el servidor.');
            Response::redirect('/admin/sitio');
            return;
        }

        $anterior = ConfiguracionSitioModel::obtener()['logo_path'] ?? null;
        ConfiguracionSitioModel::guardarLogo($nombreArchivo);
        if ($anterior) {
            @unlink($directorio . '/' . $anterior);
        }

        Session::flash('success', 'Logo actualizado.');
        Response::redirect('/admin/sitio');
    }

    public function eliminarLogo(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $actual = ConfiguracionSitioModel::obtener()['logo_path'] ?? null;
        ConfiguracionSitioModel::eliminarLogo();
        if ($actual) {
            @unlink(dirname(__DIR__, 2) . '/public/uploads/logo/' . $actual);
        }

        Session::flash('success', 'Logo eliminado.');
        Response::redirect('/admin/sitio');
    }

    // --- Configuracion global (jornada semanal, ventana nocturna) ---

    public function mostrarConfiguracion(Request $request): string
    {
        return View::render('admin/configuracion', [
            'actual' => ConfiguracionGlobalModel::masReciente(),
            'historial' => ConfiguracionGlobalModel::historial(),
        ]);
    }

    public function guardarConfiguracion(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $almuerzoActivo = (bool) $request->input('almuerzo_activo');
        $horaInicioAlmuerzo = $request->input('hora_inicio_almuerzo') ?: null;
        $horaFinAlmuerzo = $request->input('hora_fin_almuerzo') ?: null;

        if ($almuerzoActivo && (!$horaInicioAlmuerzo || !$horaFinAlmuerzo || $horaFinAlmuerzo <= $horaInicioAlmuerzo)) {
            Session::flash('error', 'Si activas el descuento de almuerzo, la hora de fin debe ser posterior a la de inicio.');
            Response::redirect('/admin/configuracion');
            return;
        }

        ConfiguracionGlobalModel::insert([
            'vigente_desde' => $request->input('vigente_desde'),
            'jornada_semanal_horas' => $request->input('jornada_semanal_horas'),
            'hora_inicio_recargo_nocturno' => $request->input('hora_inicio_recargo_nocturno'),
            'hora_fin_recargo_nocturno' => $request->input('hora_fin_recargo_nocturno'),
            'almuerzo_activo' => $almuerzoActivo ? 1 : 0,
            'hora_inicio_almuerzo' => $almuerzoActivo ? $horaInicioAlmuerzo : null,
            'hora_fin_almuerzo' => $almuerzoActivo ? $horaFinAlmuerzo : null,
            'notas' => $request->input('notas') ?: null,
        ]);

        Session::flash('success', 'Nueva configuracion registrada. Los periodos calculados antes de esta fecha no se ven afectados.');
        Response::redirect('/admin/configuracion');
    }

    // --- Matriz de porcentajes de recargo ---

    public function mostrarTiposRecargo(Request $request): string
    {
        return View::render('admin/tipos_recargo', [
            'tipos' => \App\Models\TipoRecargoModel::all('vigente_desde DESC, codigo ASC'),
        ]);
    }

    public function guardarTipoRecargo(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        TipoRecargoModel::insert([
            'codigo' => strtoupper(trim((string) $request->input('codigo'))),
            'nombre' => trim((string) $request->input('nombre')),
            'es_hora_extra' => $request->input('es_hora_extra') ? 1 : 0,
            'es_nocturno' => $request->input('es_nocturno') ? 1 : 0,
            'es_dominical_festivo' => $request->input('es_dominical_festivo') ? 1 : 0,
            'porcentaje' => $request->input('porcentaje'),
            'vigente_desde' => $request->input('vigente_desde'),
        ]);

        Session::flash('success', 'Tipo de recargo guardado.');
        Response::redirect('/admin/tipos-recargo');
    }

    // --- Festivos ---

    public function mostrarFestivos(Request $request): string
    {
        $anio = (int) ($request->query('anio') ?: date('Y'));

        return View::render('admin/festivos', [
            'anio' => $anio,
            'festivos' => FestivoModel::porAnio($anio),
        ]);
    }

    public function generarFestivos(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $anio = (int) $request->input('anio');
        $creados = (new FestivosService())->generarYGuardarAnio($anio);

        Session::flash('success', "Festivos {$anio} generados: {$creados} nuevo(s).");
        Response::redirect('/admin/festivos?anio=' . $anio);
    }

    public function crearFestivoManual(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $fecha = $request->input('fecha');
        FestivoModel::crearManual($fecha, trim((string) $request->input('nombre')));

        Session::flash('success', 'Festivo manual agregado.');
        Response::redirect('/admin/festivos?anio=' . substr($fecha, 0, 4));
    }

    public function eliminarFestivo(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->input('id');
        $anio = (int) $request->input('anio');
        FestivoModel::delete($id);

        Session::flash('success', 'Festivo eliminado.');
        Response::redirect('/admin/festivos?anio=' . $anio);
    }

    // --- Periodos no laborables (empresa) ---

    public function mostrarPeriodosNoLaborables(Request $request): string
    {
        return View::render('admin/periodos_no_laborables', [
            'periodos' => PeriodoNoLaborableModel::all(),
        ]);
    }

    public function guardarPeriodoNoLaborable(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        PeriodoNoLaborableModel::insert([
            'nombre' => trim((string) $request->input('nombre')),
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_fin' => $request->input('fecha_fin'),
            'aplica_a' => 'empresa',
            'descripcion' => $request->input('descripcion') ?: null,
        ]);

        Session::flash('success', 'Periodo no laborable creado.');
        Response::redirect('/admin/periodos-no-laborables');
    }

    public function eliminarPeriodoNoLaborable(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        PeriodoNoLaborableModel::delete((int) $request->input('id'));

        Session::flash('success', 'Periodo no laborable eliminado.');
        Response::redirect('/admin/periodos-no-laborables');
    }

    // --- Tipos de novedad ---

    public function mostrarTiposNovedad(Request $request): string
    {
        return View::render('admin/tipos_novedad', [
            'tipos' => TipoNovedadModel::all('nombre ASC'),
        ]);
    }

    public function guardarTipoNovedad(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        TipoNovedadModel::insert([
            'codigo' => strtoupper(str_replace(' ', '_', trim((string) $request->input('codigo')))),
            'nombre' => trim((string) $request->input('nombre')),
            'categoria' => $request->input('categoria'),
            'requiere_aprobacion' => $request->input('requiere_aprobacion') ? 1 : 0,
            'afecta_pago' => $request->input('afecta_pago') ? 1 : 0,
            'activo' => 1,
        ]);

        Session::flash('success', 'Tipo de novedad creado.');
        Response::redirect('/admin/tipos-novedad');
    }

    public function alternarTipoNovedad(Request $request)
    {
        if (!Session::verifyCsrf($request->input('_csrf'))) {
            Response::abort(419, 'Formulario expirado, intenta de nuevo.');
            return;
        }

        $id = (int) $request->param('id');
        $tipo = TipoNovedadModel::find($id);
        if ($tipo) {
            TipoNovedadModel::update($id, ['activo' => $tipo['activo'] ? 0 : 1]);
        }

        Response::redirect('/admin/tipos-novedad');
    }
}
