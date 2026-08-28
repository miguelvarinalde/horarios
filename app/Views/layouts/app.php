<?php
use App\Core\Auth;
use App\Core\Session;
use App\Core\View;
use App\Models\ConfiguracionSitioModel;

$usuario = Auth::usuario();
$puede = fn (string $codigo) => Auth::puede($codigo);
$esAdministrador = ($usuario['rol_nombre'] ?? null) === 'Administrador';
$path = $_SERVER['REQUEST_URI'] ?? '';
$activo = fn (string $prefijo) => str_starts_with($path, $prefijo) ? 'active' : '';
$activoExacto = fn (string $prefijo, string $excepto) => (str_starts_with($path, $prefijo) && !str_starts_with($path, $excepto)) ? 'active' : '';
$sitio = ConfiguracionSitioModel::obtener();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($titulo ?? $sitio['nombre_aplicacion']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app-shell" id="app-shell">
    <div class="sidebar-backdrop" data-menu-close></div>
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-marca">
                <?php if (!empty($sitio['logo_path'])): ?>
                    <span class="sidebar-logo-fondo"><img src="/uploads/logo/<?= View::e($sitio['logo_path']) ?>" alt="" class="sidebar-logo"></span>
                <?php endif; ?>
                <h1><?= View::e($sitio['nombre_aplicacion']) ?></h1>
            </div>
            <button type="button" class="sidebar-cerrar" data-menu-close aria-label="Cerrar menu">&times;</button>
        </div>
        <nav>
            <a href="/" class="<?= $activo('/dashboard') ?>">Inicio</a>

            <?php if ($puede('registros_tiempo.marcar')): ?>
                <div class="grupo">Mi tiempo</div>
                <a href="/registros-tiempo/marcar" class="<?= $activo('/registros-tiempo/marcar') ?>">Marcar entrada / salida</a>
            <?php endif; ?>

            <?php if ($puede('empleados.ver')): ?>
                <div class="grupo">Personal</div>
                <a href="/empleados" class="<?= $activo('/empleados') ?>">Empleados</a>
            <?php endif; ?>

            <?php if ($puede('usuarios.gestionar')): ?>
                <a href="/usuarios" class="<?= $activo('/usuarios') ?>">Usuarios</a>
            <?php endif; ?>

            <?php if ($puede('novedades.ver')): ?>
                <div class="grupo">Novedades</div>
                <a href="/novedades" class="<?= $activo('/novedades') ?>">Permisos / Vacaciones / Extras</a>
            <?php endif; ?>

            <?php if ($puede('calendario.ver')): ?>
                <a href="/calendario" class="<?= $activo('/calendario') ?>">Calendario de equipo</a>
            <?php endif; ?>

            <?php if ($puede('reportes.ver')): ?>
                <div class="grupo">Reportes</div>
                <a href="/reportes/horas-extra" class="<?= $activo('/reportes/horas-extra') ?>">Horas extra y recargos</a>
                <a href="/reportes/horas-registro" class="<?= $activo('/reportes/horas-registro') ?>">Horas trabajadas (registro)</a>
                <a href="/reportes/nomina-registro" class="<?= $activo('/reportes/nomina-registro') ?>">Resumen de nomina (registro)</a>
            <?php endif; ?>

            <?php if ($puede('dias_compensatorios.ver')): ?>
                <a href="/dias-compensatorios" class="<?= $activo('/dias-compensatorios') ?>">Dias compensatorios</a>
            <?php endif; ?>

            <?php if ($puede('registros_tiempo.ver')): ?>
                <a href="/registros-tiempo" class="<?= $activoExacto('/registros-tiempo', '/registros-tiempo/marcar') ?>">Registros de entrada/salida</a>
            <?php endif; ?>

            <?php if ($puede('admin.configuracion') || $puede('admin.roles') || $puede('admin.festivos') || $puede('admin.tipos_recargo') || $puede('admin.ms365') || $puede('admin.sitio') || $puede('admin.areas') || $esAdministrador): ?>
                <div class="grupo">Administracion</div>
                <?php if ($puede('admin.areas')): ?>
                    <a href="/admin/areas" class="<?= $activo('/admin/areas') ?>">Areas / equipos</a>
                <?php endif; ?>
                <?php if ($puede('admin.configuracion')): ?>
                    <a href="/admin/configuracion" class="<?= $activo('/admin/configuracion') ?>">Jornada y recargo nocturno</a>
                <?php endif; ?>
                <?php if ($puede('admin.tipos_recargo')): ?>
                    <a href="/admin/tipos-recargo" class="<?= $activo('/admin/tipos-recargo') ?>">Porcentajes de recargo</a>
                <?php endif; ?>
                <?php if ($puede('admin.festivos')): ?>
                    <a href="/admin/festivos" class="<?= $activo('/admin/festivos') ?>">Festivos</a>
                <?php endif; ?>
                <?php if ($puede('admin.periodos_no_laborables')): ?>
                    <a href="/admin/periodos-no-laborables" class="<?= $activo('/admin/periodos-no-laborables') ?>">Periodos no laborables</a>
                <?php endif; ?>
                <?php if ($puede('admin.tipos_novedad')): ?>
                    <a href="/admin/tipos-novedad" class="<?= $activo('/admin/tipos-novedad') ?>">Tipos de novedad</a>
                <?php endif; ?>
                <?php if ($puede('admin.roles')): ?>
                    <a href="/admin/roles" class="<?= $activo('/admin/roles') ?>">Roles y permisos</a>
                <?php endif; ?>
                <?php if ($puede('admin.ms365')): ?>
                    <a href="/admin/ms365" class="<?= $activo('/admin/ms365') ?>">Microsoft 365 (SSO)</a>
                <?php endif; ?>
                <?php if ($puede('admin.sitio')): ?>
                    <a href="/admin/sitio" class="<?= $activo('/admin/sitio') ?>">Personalizacion del sitio</a>
                <?php endif; ?>
                <?php if ($esAdministrador): ?>
                    <a href="/admin/actualizaciones" class="<?= $activo('/admin/actualizaciones') ?>">Actualizaciones</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="main">
        <div class="topbar">
            <div class="topbar-titulo">
                <button type="button" class="hamburger-btn" data-menu-toggle aria-label="Abrir menu" aria-controls="app-shell"><span></span></button>
                <div><?= View::e($titulo ?? '') ?></div>
            </div>
            <div class="topbar-acciones">
                <span class="text-muted"><?= View::e($usuario['nombre'] ?? '') ?> &middot; <?= View::e($usuario['rol_nombre'] ?? '') ?></span>
                <a href="/cambiar-password" class="btn btn-sm">Cambiar mi contrasena</a>
                <form method="post" action="/logout" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                    <button type="submit" class="btn btn-sm">Salir</button>
                </form>
            </div>
        </div>
        <div class="content">
            <?php if ($flash = Session::getFlash('success')): ?>
                <div class="alert alert-success"><?= View::e($flash) ?></div>
            <?php endif; ?>
            <?php if ($flash = Session::getFlash('error')): ?>
                <div class="alert alert-error"><?= View::e($flash) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </div>
        <?php if (!empty($sitio['footer_texto'])): ?>
            <footer class="footer"><?= nl2br(View::e($sitio['footer_texto'])) ?></footer>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
