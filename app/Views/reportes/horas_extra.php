<?php use App\Core\Auth; use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Reporte de horas extra y recargos</h2>
    <p class="text-muted">Por empleado, cada tipo de recargo y el total de horas a pagar. Por defecto muestra el mes calendario actual; puedes elegir cualquier otro periodo ya creado.</p>

    <div style="display:flex;gap:.5rem;margin-bottom:1rem">
        <a href="/reportes/horas-extra?rango=dia" class="btn btn-sm">Hoy</a>
        <a href="/reportes/horas-extra?rango=semana" class="btn btn-sm">Esta semana</a>
        <a href="/reportes/horas-extra?rango=mes" class="btn btn-sm">Este mes</a>
    </div>
    <p class="text-muted" style="margin-top:-.75rem;font-size:.9em">Consulta rapida sin crear un periodo a mano: busca (o crea automaticamente, si tienes permiso de calculo) el periodo que cubre ese rango.</p>

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

<?php if (!empty($puedeEliminarPeriodos)): ?>
<div class="card">
    <h2>Eliminar periodos</h2>
    <p class="text-muted">Elimina periodos que ya no se usen (por ejemplo, pruebas o rangos creados por error). "Calculos" muestra cuantas filas de horas ya calculadas tiene ese periodo: eliminarlo tambien borra ese calculo, no solo el periodo.</p>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Nombre</th><th>Desde</th><th>Hasta</th><th>Calculos</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($periodosConConteo as $p): ?>
            <tr>
                <td><?= View::e($p['nombre']) ?></td>
                <td><?= View::e($p['fecha_inicio']) ?></td>
                <td><?= View::e($p['fecha_fin']) ?></td>
                <td>
                    <?php if ((int) $p['total_calculos'] > 0): ?>
                        <span class="badge badge-pendiente"><?= (int) $p['total_calculos'] ?> filas</span>
                    <?php else: ?>
                        <span class="text-muted">Sin usar</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/reportes/periodos/eliminar" data-confirm="<?= (int) $p['total_calculos'] > 0
                        ? '¿Eliminar el periodo &quot;' . View::e($p['nombre']) . '&quot;? Tiene ' . (int) $p['total_calculos'] . ' filas de calculo ya hecho, tambien se borraran.'
                        : '¿Eliminar el periodo &quot;' . View::e($p['nombre']) . '&quot;?' ?>">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($periodosConConteo)): ?>
            <tr><td colspan="5" class="text-muted">No hay periodos de calculo creados todavia.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
