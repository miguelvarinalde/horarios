<?php use App\Core\Auth; use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 class="mb-0">Novedades (permisos, vacaciones, incapacidades, horas extra)</h2>
        <?php if (Auth::puede('novedades.crear')): ?>
            <a href="/novedades/crear" class="btn btn-primary">Nueva novedad</a>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
    <table>
        <thead>
        <tr><th>Empleado</th><th>Tipo</th><th>Fecha</th><th>Horario</th><th>Comentario</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($novedades as $n): ?>
            <tr>
                <td><?= View::e($n['empleado_nombre']) ?></td>
                <td><?= View::e($n['tipo_nombre']) ?></td>
                <td><?= View::e($n['fecha']) ?></td>
                <td><?= $n['hora_inicio'] ? View::e($n['hora_inicio']) . ' - ' . View::e($n['hora_fin']) : 'Dia completo' ?></td>
                <td class="text-muted"><?= View::e($n['comentario']) ?></td>
                <td><span class="badge badge-<?= $n['estado'] ?>"><?= ucfirst($n['estado']) ?></span></td>
                <td>
                    <?php if ($puedeAprobar && $n['estado'] === 'pendiente'): ?>
                        <form method="post" action="/novedades/<?= (int) $n['id'] ?>/aprobar" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <button type="submit" class="btn btn-sm">Aprobar</button>
                        </form>
                        <form method="post" action="/novedades/<?= (int) $n['id'] ?>/rechazar" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Rechazar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($novedades)): ?>
            <tr><td colspan="7" class="text-muted">No hay novedades registradas.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
