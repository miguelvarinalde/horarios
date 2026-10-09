<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Periodos no laborables</h2>
    <p class="text-muted">Durante estos dias el horario programado no suma horas (solo cuentan las novedades de trabajo en festivo). Cada periodo aplica a toda la empresa o solo a las areas que elijas.</p>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th>Aplica a</th><th>Descripcion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($periodos as $p): ?>
            <tr>
                <td><?= View::e($p['nombre']) ?></td>
                <td><?= View::e($p['fecha_inicio']) ?></td>
                <td><?= View::e($p['fecha_fin']) ?></td>
                <td>
                    <?php if (empty($p['areas'])): ?>
                        <span class="badge badge-aprobado">Toda la empresa</span>
                    <?php else: ?>
                        <?= View::e(implode(', ', $p['areas'])) ?>
                    <?php endif; ?>
                </td>
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
            <tr><td colspan="6" class="text-muted">No hay periodos no laborables registrados.</td></tr>
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
            <label>Aplica a</label>
            <div style="border:1px solid #d1d5db;border-radius:6px;padding:.5rem .75rem">
                <?php foreach ($areas as $a): ?>
                    <label style="display:flex;align-items:center;gap:.5rem;font-weight:normal;padding:.2rem 0">
                        <input type="checkbox" name="areas[]" value="<?= (int) $a['id'] ?>">
                        <?= View::e($a['nombre']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <small class="text-muted">Sin ninguna area marcada, el periodo aplica a TODA la empresa. Si marcas una o varias, solo se suspende el horario de los empleados de esas areas (un empleado sin area solo se ve afectado por periodos de toda la empresa).</small>
        </div>
        <div class="form-group">
            <label>Descripcion</label>
            <input type="text" name="descripcion">
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
