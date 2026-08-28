<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Periodos no laborables de empresa</h2>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th>Descripcion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($periodos as $p): ?>
            <tr>
                <td><?= View::e($p['nombre']) ?></td>
                <td><?= View::e($p['fecha_inicio']) ?></td>
                <td><?= View::e($p['fecha_fin']) ?></td>
                <td><?= View::e($p['descripcion']) ?></td>
                <td>
                    <form method="post" action="/admin/periodos-no-laborables/eliminar" data-confirm="¿Eliminar este periodo?">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($periodos)): ?>
            <tr><td colspan="5" class="text-muted">No hay periodos no laborables registrados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Nuevo periodo no laborable</h2>
    <form method="post" action="/admin/periodos-no-laborables">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-group">
            <label>Nombre</label>
            <input type="text" name="nombre" required placeholder="Ej. Cierre colectivo fin de año">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Desde</label>
                <input type="date" name="fecha_inicio" required>
            </div>
            <div class="form-group">
                <label>Hasta</label>
                <input type="date" name="fecha_fin" required>
            </div>
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <input type="text" name="descripcion">
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
