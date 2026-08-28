<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Dias compensatorios (trabajo dominical/festivo)</h2>
    <p class="text-muted">
        Segun la Ley 2466 de 2025: si un empleado trabaja hasta 2 domingos/festivos en el mes calendario (<strong>ocasional</strong>),
        puede elegir entre el recargo o un dia de descanso compensatorio. Si trabaja 3 o mas ese mes (<strong>habitual</strong>),
        tiene derecho a ambos. La clasificacion se recalcula automaticamente al ejecutar el motor de calculo.
    </p>

    <form method="get" action="/dias-compensatorios" style="display:flex;gap:.5rem;align-items:end;margin-bottom:1rem">
        <div class="form-group mb-0">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= View::e($desde ?? '') ?>">
        </div>
        <div class="form-group mb-0">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= View::e($hasta ?? '') ?>">
        </div>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Empleado</th>
            <th>Fecha trabajada</th>
            <th>Clasificacion</th>
            <th>Tratamiento</th>
            <th>Descanso tomado</th>
            <?php if ($puedeGestionar): ?><th></th><?php endif; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($dias as $d): ?>
            <tr>
                <td><?= View::e($d['empleado_nombre']) ?></td>
                <td><?= View::e($d['fecha_trabajada']) ?></td>
                <td>
                    <?= $d['clasificacion'] === 'habitual'
                        ? '<span class="badge badge-pendiente">Habitual</span>'
                        : '<span class="badge badge-aprobado">Ocasional</span>' ?>
                </td>
                <td>
                    <?php
                    $etiquetas = ['recargo' => 'Solo recargo', 'descanso_compensatorio' => 'Solo descanso', 'ambos' => 'Recargo + descanso'];
                    echo View::e($etiquetas[$d['tratamiento']] ?? $d['tratamiento']);
                    ?>
                </td>
                <td>
                    <?php if (in_array($d['tratamiento'], ['descanso_compensatorio', 'ambos'], true)): ?>
                        <?= $d['descanso_tomado_fecha'] ? View::e($d['descanso_tomado_fecha']) : '<span class="badge badge-pendiente">Pendiente</span>' ?>
                    <?php else: ?>
                        <span class="text-muted">No aplica</span>
                    <?php endif; ?>
                </td>
                <?php if ($puedeGestionar): ?>
                    <td>
                        <?php if ($d['clasificacion'] === 'ocasional'): ?>
                            <form method="post" action="/dias-compensatorios/<?= (int) $d['id'] ?>/tratamiento" style="display:inline-flex;gap:.3rem">
                                <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                                <select name="tratamiento" style="width:auto">
                                    <option value="recargo" <?= $d['tratamiento'] === 'recargo' ? 'selected' : '' ?>>Solo recargo</option>
                                    <option value="descanso_compensatorio" <?= $d['tratamiento'] === 'descanso_compensatorio' ? 'selected' : '' ?>>Solo descanso</option>
                                </select>
                                <button type="submit" class="btn btn-sm">Guardar</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($d['tratamiento'], ['descanso_compensatorio', 'ambos'], true) && !$d['descanso_tomado_fecha']): ?>
                            <form method="post" action="/dias-compensatorios/<?= (int) $d['id'] ?>/descanso-tomado" style="display:inline-flex;gap:.3rem;margin-top:.3rem">
                                <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                                <input type="date" name="descanso_tomado_fecha" style="width:auto" required>
                                <button type="submit" class="btn btn-sm">Marcar tomado</button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($dias)): ?>
            <tr><td colspan="6" class="text-muted">No hay dias dominicales/festivos trabajados registrados todavia. Se generan automaticamente al ejecutar el calculo de un periodo.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
