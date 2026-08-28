<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ActualizacionController;
use App\Controllers\AdminConfigController;
use App\Controllers\AuthController;
use App\Controllers\CalendarioController;
use App\Controllers\CambiarPasswordController;
use App\Controllers\DashboardController;
use App\Controllers\DiaCompensatorioController;
use App\Controllers\EmpleadoController;
use App\Controllers\HorarioController;
use App\Controllers\InstalacionController;
use App\Controllers\Ms365AuthController;
use App\Controllers\Ms365ConfigController;
use App\Controllers\NovedadController;
use App\Controllers\RegistroTiempoController;
use App\Controllers\ReporteController;
use App\Controllers\RolController;
use App\Controllers\UsuarioController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Router;
use App\Core\SecurityHeaders;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\RbacMiddleware;
use App\Middleware\SoloAdministradorMiddleware;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set($config['timezone']);
error_reporting($config['debug'] ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', $config['debug'] ? '1' : '0');

SecurityHeaders::aplicar();
Session::start();

$container = new Container();
$router = new Router();

// --- Instalacion (solo funciona antes de la primera instalacion) ---
$router->get('/instalar', [InstalacionController::class, 'mostrar']);
$router->post('/instalar', [InstalacionController::class, 'instalar']);

// --- Autenticacion ---
$router->get('/login', [AuthController::class, 'mostrarLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);

// --- Cambio de contrasena (obligatorio tras crear/resetear, o voluntario) ---
$router->get('/cambiar-password', [CambiarPasswordController::class, 'mostrar'], [AuthMiddleware::class]);
$router->post('/cambiar-password', [CambiarPasswordController::class, 'guardar'], [AuthMiddleware::class]);

// --- Usuarios (ver y resetear contrasenas) ---
$router->get('/usuarios', [UsuarioController::class, 'index'], [[RbacMiddleware::class, 'usuarios.gestionar']]);
$router->post('/usuarios/{id:\d+}/resetear-password', [UsuarioController::class, 'resetearPassword'], [[RbacMiddleware::class, 'usuarios.gestionar']]);

// --- SSO Microsoft 365 (Fase 2) ---
$router->get('/auth/microsoft', [Ms365AuthController::class, 'iniciar']);
$router->get('/auth/microsoft/callback', [Ms365AuthController::class, 'callback']);

// --- Dashboard ---
$router->get('/', [DashboardController::class, 'index'], [AuthMiddleware::class]);
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);

// --- Empleados ---
$router->get('/empleados', [EmpleadoController::class, 'index'], [[RbacMiddleware::class, 'empleados.ver']]);
$router->get('/empleados/crear', [EmpleadoController::class, 'crear'], [[RbacMiddleware::class, 'empleados.crear']]);
$router->post('/empleados', [EmpleadoController::class, 'guardar'], [[RbacMiddleware::class, 'empleados.crear']]);
$router->get('/empleados/{id:\d+}/editar', [EmpleadoController::class, 'editar'], [[RbacMiddleware::class, 'empleados.editar']]);
$router->put('/empleados/{id:\d+}', [EmpleadoController::class, 'actualizar'], [[RbacMiddleware::class, 'empleados.editar']]);

// --- Horarios (anidados bajo empleado) ---
$router->get('/empleados/{empleadoId:\d+}/horarios', [HorarioController::class, 'index'], [[RbacMiddleware::class, 'horarios.ver']]);
$router->get('/empleados/{empleadoId:\d+}/horarios/crear', [HorarioController::class, 'crear'], [[RbacMiddleware::class, 'horarios.crear']]);
$router->post('/empleados/{empleadoId:\d+}/horarios', [HorarioController::class, 'guardar'], [[RbacMiddleware::class, 'horarios.crear']]);
$router->post('/empleados/{empleadoId:\d+}/horarios/eliminar', [HorarioController::class, 'eliminar'], [[RbacMiddleware::class, 'horarios.editar']]);
$router->get('/empleados/{empleadoId:\d+}/horarios/{vigenteDesde}/editar', [HorarioController::class, 'editarForm'], [[RbacMiddleware::class, 'horarios.editar']]);
$router->post('/empleados/{empleadoId:\d+}/horarios/{vigenteDesde}/editar', [HorarioController::class, 'actualizar'], [[RbacMiddleware::class, 'horarios.editar']]);

// --- Novedades ---
$router->get('/novedades', [NovedadController::class, 'index'], [[RbacMiddleware::class, 'novedades.ver']]);
$router->get('/novedades/crear', [NovedadController::class, 'crear'], [[RbacMiddleware::class, 'novedades.crear']]);
$router->post('/novedades', [NovedadController::class, 'guardar'], [[RbacMiddleware::class, 'novedades.crear']]);
$router->post('/novedades/{id:\d+}/aprobar', [NovedadController::class, 'aprobar'], [[RbacMiddleware::class, 'novedades.aprobar']]);
$router->post('/novedades/{id:\d+}/rechazar', [NovedadController::class, 'rechazar'], [[RbacMiddleware::class, 'novedades.aprobar']]);

// --- Registro de entrada/salida (auto-marcacion con ubicacion) ---
$router->get('/registros-tiempo/marcar', [RegistroTiempoController::class, 'marcar'], [[RbacMiddleware::class, 'registros_tiempo.marcar']]);
$router->post('/registros-tiempo/consentimiento', [RegistroTiempoController::class, 'aceptarConsentimiento'], [[RbacMiddleware::class, 'registros_tiempo.marcar']]);
$router->post('/registros-tiempo', [RegistroTiempoController::class, 'registrar'], [[RbacMiddleware::class, 'registros_tiempo.marcar']]);
$router->get('/registros-tiempo', [RegistroTiempoController::class, 'index'], [[RbacMiddleware::class, 'registros_tiempo.ver']]);
$router->get('/registros-tiempo/crear', [RegistroTiempoController::class, 'crearForm'], [[RbacMiddleware::class, 'registros_tiempo.corregir']]);
$router->post('/registros-tiempo/crear', [RegistroTiempoController::class, 'crear'], [[RbacMiddleware::class, 'registros_tiempo.corregir']]);
$router->get('/registros-tiempo/{id:\d+}/editar', [RegistroTiempoController::class, 'editarForm'], [[RbacMiddleware::class, 'registros_tiempo.corregir']]);
$router->post('/registros-tiempo/{id:\d+}/editar', [RegistroTiempoController::class, 'editar'], [[RbacMiddleware::class, 'registros_tiempo.corregir']]);
$router->post('/registros-tiempo/{id:\d+}/eliminar', [RegistroTiempoController::class, 'eliminar'], [[RbacMiddleware::class, 'registros_tiempo.corregir']]);

// --- Dias compensatorios (trabajo dominical/festivo, Ley 2466 de 2025) ---
$router->get('/dias-compensatorios', [DiaCompensatorioController::class, 'index'], [[RbacMiddleware::class, 'dias_compensatorios.ver']]);
$router->post('/dias-compensatorios/{id:\d+}/tratamiento', [DiaCompensatorioController::class, 'actualizarTratamiento'], [[RbacMiddleware::class, 'dias_compensatorios.gestionar']]);
$router->post('/dias-compensatorios/{id:\d+}/descanso-tomado', [DiaCompensatorioController::class, 'marcarDescansoTomado'], [[RbacMiddleware::class, 'dias_compensatorios.gestionar']]);

// --- Calendario de equipo ---
$router->get('/calendario', [CalendarioController::class, 'index'], [[RbacMiddleware::class, 'calendario.ver']]);

// --- Reportes ---
$router->get('/reportes/horas-extra', [ReporteController::class, 'index'], [[RbacMiddleware::class, 'reportes.ver']]);
$router->post('/reportes/periodos', [ReporteController::class, 'crearPeriodo'], [[RbacMiddleware::class, 'calculo.ejecutar']]);
$router->post('/reportes/horas-extra/calcular', [ReporteController::class, 'calcular'], [[RbacMiddleware::class, 'calculo.ejecutar']]);
$router->get('/reportes/horas-extra/exportar-excel', [ReporteController::class, 'exportarExcel'], [[RbacMiddleware::class, 'reportes.exportar']]);
$router->get('/reportes/horas-extra/exportar-pdf', [ReporteController::class, 'exportarPdf'], [[RbacMiddleware::class, 'reportes.exportar']]);
$router->get('/reportes/horas-registro', [ReporteController::class, 'horasRegistro'], [[RbacMiddleware::class, 'reportes.ver']]);
$router->get('/reportes/horas-registro/exportar-excel', [ReporteController::class, 'exportarHorasRegistroExcel'], [[RbacMiddleware::class, 'reportes.exportar']]);
$router->get('/reportes/nomina-registro', [ReporteController::class, 'nominaRegistro'], [[RbacMiddleware::class, 'reportes.ver']]);
$router->get('/reportes/nomina-registro/exportar-excel', [ReporteController::class, 'exportarNominaRegistroExcel'], [[RbacMiddleware::class, 'reportes.exportar']]);

// --- Administracion: configuracion global ---
$router->get('/admin/configuracion', [AdminConfigController::class, 'mostrarConfiguracion'], [[RbacMiddleware::class, 'admin.configuracion']]);
$router->post('/admin/configuracion', [AdminConfigController::class, 'guardarConfiguracion'], [[RbacMiddleware::class, 'admin.configuracion']]);

// --- Personalizacion del sitio (nombre, logo, pie de pagina) ---
$router->get('/admin/sitio', [AdminConfigController::class, 'mostrarSitio'], [[RbacMiddleware::class, 'admin.sitio']]);
$router->post('/admin/sitio', [AdminConfigController::class, 'guardarSitioTexto'], [[RbacMiddleware::class, 'admin.sitio']]);
$router->post('/admin/sitio/logo', [AdminConfigController::class, 'subirLogo'], [[RbacMiddleware::class, 'admin.sitio']]);
$router->post('/admin/sitio/logo/eliminar', [AdminConfigController::class, 'eliminarLogo'], [[RbacMiddleware::class, 'admin.sitio']]);

// --- Administracion: tipos de recargo ---
$router->get('/admin/tipos-recargo', [AdminConfigController::class, 'mostrarTiposRecargo'], [[RbacMiddleware::class, 'admin.tipos_recargo']]);
$router->post('/admin/tipos-recargo', [AdminConfigController::class, 'guardarTipoRecargo'], [[RbacMiddleware::class, 'admin.tipos_recargo']]);

// --- Administracion: festivos ---
$router->get('/admin/festivos', [AdminConfigController::class, 'mostrarFestivos'], [[RbacMiddleware::class, 'admin.festivos']]);
$router->post('/admin/festivos', [AdminConfigController::class, 'crearFestivoManual'], [[RbacMiddleware::class, 'admin.festivos']]);
$router->post('/admin/festivos/generar', [AdminConfigController::class, 'generarFestivos'], [[RbacMiddleware::class, 'admin.festivos']]);
$router->post('/admin/festivos/eliminar', [AdminConfigController::class, 'eliminarFestivo'], [[RbacMiddleware::class, 'admin.festivos']]);

// --- Administracion: periodos no laborables ---
$router->get('/admin/periodos-no-laborables', [AdminConfigController::class, 'mostrarPeriodosNoLaborables'], [[RbacMiddleware::class, 'admin.periodos_no_laborables']]);
$router->post('/admin/periodos-no-laborables', [AdminConfigController::class, 'guardarPeriodoNoLaborable'], [[RbacMiddleware::class, 'admin.periodos_no_laborables']]);
$router->post('/admin/periodos-no-laborables/eliminar', [AdminConfigController::class, 'eliminarPeriodoNoLaborable'], [[RbacMiddleware::class, 'admin.periodos_no_laborables']]);

// --- Administracion: tipos de novedad ---
$router->get('/admin/tipos-novedad', [AdminConfigController::class, 'mostrarTiposNovedad'], [[RbacMiddleware::class, 'admin.tipos_novedad']]);
$router->post('/admin/tipos-novedad', [AdminConfigController::class, 'guardarTipoNovedad'], [[RbacMiddleware::class, 'admin.tipos_novedad']]);
$router->post('/admin/tipos-novedad/{id:\d+}/alternar', [AdminConfigController::class, 'alternarTipoNovedad'], [[RbacMiddleware::class, 'admin.tipos_novedad']]);

// --- Administracion: Microsoft 365 (SSO) ---
$router->get('/admin/ms365', [Ms365ConfigController::class, 'mostrar'], [[RbacMiddleware::class, 'admin.ms365']]);
$router->post('/admin/ms365', [Ms365ConfigController::class, 'guardar'], [[RbacMiddleware::class, 'admin.ms365']]);

// --- Administracion: roles y permisos ---
$router->get('/admin/areas', [AdminConfigController::class, 'mostrarAreas'], [[RbacMiddleware::class, 'admin.areas']]);
$router->post('/admin/areas', [AdminConfigController::class, 'guardarArea'], [[RbacMiddleware::class, 'admin.areas']]);
$router->post('/admin/areas/{id:\d+}/alternar', [AdminConfigController::class, 'alternarArea'], [[RbacMiddleware::class, 'admin.areas']]);

$router->get('/admin/roles', [RolController::class, 'index'], [[RbacMiddleware::class, 'admin.roles']]);
$router->post('/admin/roles', [RolController::class, 'crear'], [[RbacMiddleware::class, 'admin.roles']]);
$router->post('/admin/roles/{id:\d+}/permisos', [RolController::class, 'actualizarPermisos'], [[RbacMiddleware::class, 'admin.roles']]);
$router->post('/admin/roles/{id:\d+}/eliminar', [RolController::class, 'eliminar'], [[RbacMiddleware::class, 'admin.roles']]);

// --- Administracion: aplicar migraciones pendientes (actualizaciones sin Terminal) ---
// Depende del ROL Administrador (SoloAdministradorMiddleware), no de un
// permiso puntual: esta pantalla debe seguir siendo alcanzable aunque un
// permiso nuevo todavia no se haya sembrado en la base de datos.
$router->get('/admin/actualizaciones', [ActualizacionController::class, 'mostrar'], [SoloAdministradorMiddleware::class]);
$router->post('/admin/actualizaciones', [ActualizacionController::class, 'aplicar'], [SoloAdministradorMiddleware::class]);

$router->dispatch($container, new Request());
