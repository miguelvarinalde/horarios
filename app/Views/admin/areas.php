<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Areas / equipos</h2>
    <p class="text-muted">
        Definen que empleados puede ver y gestionar un Supervisor (horarios, novedades, calendario,
        registros de entrada/salida y reportes de nomina): solo los que estan en su misma area.
        RRHH, Administrador y Auditor siguen viendo a todos, sin importar el area
        (se puede ajustar en <a href="/admin/roles">Roles y permisos</a>, permiso "equipos.ver_todas").
    </p>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Nombre</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($areas as $a): ?>
            <tr>
                <td><?= View::e($a['nombre']) ?></td>
                <td><?= $a['activo'] ? '<span class="badge badge-aprobado">Activa</span>' : '<span class="badge badge-rechazado">Inactiva</span>' ?></td>
                <td>
                    <form method="post" action="/admin/areas/<?= (int) $a['id'] ?>/alternar">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <button type="submit" class="btn btn-sm"><?= $a['activo'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($areas)): ?>
            <tr><td colspan="3" class="text-muted">Aun no hay areas creadas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Nueva area</h2>
    <form method="post" action="/admin/areas">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-group">
            <label>Nombre</label>
            <input type="text" name="nombre" required placeholder="Ej. Mantenimiento, Sistemas, Biblioteca">
        </div>
        <button type="submit" class="btn btn-primary">Crear area</button>
    </form>
</div>
