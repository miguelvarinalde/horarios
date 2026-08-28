<?php use App\Core\Auth; use App\Core\View; ?>
<div class="card">
    <h2>Resumen de nomina segun registro</h2>
    <p class="text-muted">
        Por empleado, cada tipo de recargo y el total a pagar, calculado con base en las
        <strong>marcaciones reales de entrada/salida</strong> (no el horario asignado). Por defecto
        muestra el mes calendario actual.
    </p>

    <form method="get" action="/reportes/nomina-registro" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:1rem">
        <div class="form-group mb-0">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= View::e($desde) ?>">
        </div>
        <div class="form-group mb-0">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= View::e($hasta) ?>">
        </div>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if (Auth::puede('reportes.exportar')): ?>
        <a href="/reportes/nomina-registro/exportar-excel?desde=<?= View::e($desde) ?>&hasta=<?= View::e($hasta) ?>" class="btn" style="margin-bottom:1rem;display:inline-block">Exportar Excel</a>
    <?php endif; ?>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Empleado</th>
            <?php foreach ($columnas as $c): ?><th><?= View::e($c) ?></th><?php endforeach; ?>
            <th>Total</th>
            <th>Dias incompletos</th>
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
                <td>
                    <?php if ($f['dias_incompletos'] > 0): ?>
                        <span class="badge badge-rechazado" title="Dias con marcaciones que no alternan entrada/salida correctamente: no suman horas, revisa y corrige en Registros de entrada/salida.">
                            <?= (int) $f['dias_incompletos'] ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($filas)): ?>
            <tr><td colspan="<?= count($columnas) + 3 ?>" class="text-muted">No hay empleados en tu alcance, o no hay marcaciones en el rango seleccionado.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
