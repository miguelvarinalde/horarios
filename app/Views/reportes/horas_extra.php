<?php use App\Core\Auth; use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Reporte de horas extra y recargos</h2>
    <p class="text-muted">Por empleado, cada tipo de recargo y el total de horas a pagar. Por defecto muestra el mes calendario actual; puedes elegir cualquier otro periodo ya creado.</p>

    <?php if (!empty($periodos)): ?>
        <form method="get" action="/reportes/horas-extra" style="display:flex;gap:.5rem;align-items:end;margin-bottom:1rem">
            <div class="form-group mb-0">
                <label>Periodo</label>
                <select name="periodo_id" data-autosubmit>
                    <?php foreach ($periodos as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $p['id'] == $periodoId ? 'selected' : '' ?>>
                            <?= View::e($p['nombre']) ?> (<?= View::e($p['fecha_inicio']) ?> a <?= View::e($p['fecha_fin']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($periodo && Auth::puede('calculo.ejecutar')): ?>
        <form method="post" action="/reportes/horas-extra/calcular" style="margin-bottom:1rem">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <input type="hidden" name="periodo_id" value="<?= (int) $periodoId ?>">
            <button type="submit" class="btn btn-primary">
                <?= Auth::veTodasLasAreas() ? 'Calcular / recalcular este periodo (todos los empleados)' : 'Calcular / recalcular este periodo (tu area)' ?>
            </button>
            <?php if (!Auth::veTodasLasAreas()): ?>
                <p class="text-muted" style="margin:.4rem 0 0">Solo recalcula a los empleados de tu propia area.</p>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($periodo && Auth::puede('reportes.exportar')): ?>
        <a href="/reportes/horas-extra/exportar-excel?periodo_id=<?= (int) $periodoId ?>" class="btn">Exportar Excel</a>
        <a href="/reportes/horas-extra/exportar-pdf?periodo_id=<?= (int) $periodoId ?>" class="btn">Exportar PDF</a>
    <?php endif; ?>

    <?php if ($periodo): ?>
        <div class="table-responsive">
        <table style="margin-top:1rem">
            <thead>
            <tr>
                <th>Empleado</th>
                <?php foreach ($columnas as $c): ?><th><?= View::e($c) ?></th><?php endforeach; ?>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($filas as $f): ?>
                <tr>
                    <td><?= View::e($f['empleado_nombre']) ?></td>
                    <?php foreach ($columnas as $c): ?>
                        <td><?= number_format($f['recargos'][$c] ?? 0, 2) ?></td>
                    <?php endforeach; ?>
                    <td><strong><?= number_format($f['total_horas'], 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($filas)): ?>
                <tr><td colspan="<?= count($columnas) + 2 ?>" class="text-muted">Sin resultados. Ejecuta el calculo para este periodo.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php elseif (Auth::puede('calculo.ejecutar')): ?>
        <p class="text-muted">No hay periodos de calculo creados todavia.</p>
    <?php else: ?>
        <p class="text-muted">Todavia no hay un calculo hecho para el mes actual. Pide a RRHH o al Administrador que lo ejecute.</p>
    <?php endif; ?>
</div>

<?php if (Auth::puede('calculo.ejecutar')): ?>
<div class="card">
    <h2>Nuevo periodo de calculo</h2>
    <form method="post" action="/reportes/periodos">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required placeholder="Ej. Quincena 1 - Julio 2026">
            </div>
            <div class="form-group">
                <label>Desde</label>
                <input type="date" name="fecha_inicio" required>
            </div>
            <div class="form-group">
                <label>Hasta</label>
                <input type="date" name="fecha_fin" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Crear periodo</button>
    </form>
</div>
<?php endif; ?>
