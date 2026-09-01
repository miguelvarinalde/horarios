<?php use App\Core\Auth; use App\Core\View; ?>
<div class="card">
    <h2>Horas trabajadas segun registro (dia por dia)</h2>
    <p class="text-muted">
        Este informe se calcula a partir de las marcaciones reales de entrada/salida, no del horario asignado.
        Es un informe de verificacion/auditoria; el calculo legal de nomina sigue siendo
        <a href="/reportes/horas-extra">Horas extra y recargos</a>.
    </p>

    <form method="get" action="/reportes/horas-registro" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:1rem">
        <?php if (count($empleados) > 1): ?>
            <div class="form-group mb-0">
                <label>Empleado</label>
                <select name="empleado_id" data-autosubmit>
                    <?php foreach ($empleados as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= (int) $e['id'] === $empleadoId ? 'selected' : '' ?>><?= View::e($e['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php elseif (count($empleados) === 1): ?>
            <input type="hidden" name="empleado_id" value="<?= (int) $empleados[0]['id'] ?>">
        <?php endif; ?>
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

    <?php if ($empleadoId && Auth::puede('reportes.exportar')): ?>
        <a href="/reportes/horas-registro/exportar-excel?empleado_id=<?= (int) $empleadoId ?>&desde=<?= View::e($desde) ?>&hasta=<?= View::e($hasta) ?>" class="btn" style="margin-bottom:1rem;display:inline-block">Exportar Excel</a>
    <?php endif; ?>

    <?php if (empty($empleados)): ?>
        <p class="text-muted">No tienes un empleado asociado, o no hay empleados en tu alcance.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Marcaciones</th>
                <?php foreach ($columnas as $c): ?>
                    <th title="<?= View::e($nombresPorCodigo[$c] ?? $c) ?>"><?= View::e($c) ?></th>
                <?php endforeach; ?>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($informe as $dia): ?>
                <tr>
                    <td>
                        <?= View::e($dia['fecha']) ?>
                        <?php if ($dia['es_domingo_o_festivo']): ?>
                            <span class="badge badge-pendiente">Dom/Festivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($dia['estado'] === 'completo'): ?>
                            <span class="badge badge-aprobado">Completo</span>
                        <?php elseif ($dia['estado'] === 'cerrado_automatico'): ?>
                            <span class="badge badge-pendiente" title="<?= View::e($dia['nota']) ?>">Cerrado automatico</span>
                        <?php elseif ($dia['estado'] === 'incompleto'): ?>
                            <span class="badge badge-rechazado" title="<?= View::e($dia['nota']) ?>">Incompleto</span>
                        <?php else: ?>
                            <span class="text-muted">Sin marcaciones</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($dia['marcaciones'])): ?>
                            <span class="text-muted">&mdash;</span>
                        <?php else: ?>
                            <?php foreach ($dia['marcaciones'] as $m): ?>
                                <?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Salida' ?> <?= substr($m['fecha_hora'], 11, 5) ?><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($dia['estado'] === 'cerrado_automatico' && $dia['salida_estimada']): ?>
                            <span class="text-muted">Salida estimada <?= substr($dia['salida_estimada'], 0, 5) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($columnas as $c): ?>
                        <td><?= isset($dia['recargos'][$c]) ? number_format($dia['recargos'][$c], 2) : '' ?></td>
                    <?php endforeach; ?>
                    <td><strong><?= in_array($dia['estado'], ['completo', 'cerrado_automatico'], true) ? number_format($dia['horas_totales'], 2) : '&mdash;' ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($informe)): ?>
                <tr><td colspan="<?= count($columnas) + 4 ?>" class="text-muted">Sin datos para el rango seleccionado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
