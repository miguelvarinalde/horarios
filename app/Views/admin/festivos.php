<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 class="mb-0">Festivos <?= $anio ?></h2>
        <form method="get" action="/admin/festivos" style="display:flex;gap:.5rem">
            <input type="number" name="anio" value="<?= $anio ?>" style="width:6rem">
            <button type="submit" class="btn">Ver</button>
        </form>
    </div>

    <form method="post" action="/admin/festivos/generar" style="margin:1rem 0">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <button type="submit" class="btn btn-primary">Generar/regenerar festivos <?= $anio ?> (fijos + Ley Emiliani + Semana Santa)</button>
    </form>

    <div class="table-responsive">
    <table>
        <thead><tr><th>Fecha</th><th>Nombre</th><th>Tipo</th><th>Origen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($festivos as $f): ?>
            <tr>
                <td><?= View::e($f['fecha']) ?></td>
                <td><?= View::e($f['nombre']) ?></td>
                <td><?= View::e($f['tipo']) ?></td>
                <td><?= View::e($f['origen']) ?></td>
                <td>
                    <form method="post" action="/admin/festivos/eliminar" data-confirm="¿Eliminar este festivo?">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <input type="hidden" name="anio" value="<?= $anio ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($festivos)): ?>
            <tr><td colspan="5" class="text-muted">No hay festivos generados para <?= $anio ?> todavia.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Agregar festivo manual</h2>
    <form method="post" action="/admin/festivos">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Fecha</label>
                <input type="date" name="fecha" required>
            </div>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Agregar</button>
    </form>
</div>
