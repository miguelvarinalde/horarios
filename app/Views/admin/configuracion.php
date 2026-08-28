<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Jornada semanal y recargo nocturno</h2>
    <p class="text-muted">Configuracion actualmente vigente:</p>
    <?php if ($actual): ?>
        <ul>
            <li>Jornada semanal: <strong><?= View::e((string) $actual['jornada_semanal_horas']) ?> horas</strong></li>
            <li>Recargo nocturno desde las <strong><?= View::e($actual['hora_inicio_recargo_nocturno']) ?></strong> hasta las <strong><?= View::e($actual['hora_fin_recargo_nocturno']) ?></strong></li>
            <li>
                Descuento automatico de almuerzo:
                <?php if (!empty($actual['almuerzo_activo'])): ?>
                    <strong>Activo, de <?= View::e($actual['hora_inicio_almuerzo']) ?> a <?= View::e($actual['hora_fin_almuerzo']) ?></strong>
                <?php else: ?>
                    <span class="text-muted">Inactivo</span>
                <?php endif; ?>
            </li>
            <li>Vigente desde: <?= View::e($actual['vigente_desde']) ?></li>
        </ul>
    <?php else: ?>
        <p>No hay configuracion registrada todavia.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Registrar nueva vigencia</h2>
    <p class="text-muted">No se edita la configuracion existente: se crea una nueva vigencia a partir de una fecha, de modo que los periodos ya calculados con la ley anterior no cambien retroactivamente.</p>
    <form method="post" action="/admin/configuracion">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Vigente desde</label>
                <input type="date" name="vigente_desde" required>
            </div>
            <div class="form-group">
                <label>Jornada semanal (horas)</label>
                <input type="number" step="0.01" name="jornada_semanal_horas" required value="<?= View::e((string) ($actual['jornada_semanal_horas'] ?? '42.00')) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Inicio recargo nocturno</label>
                <input type="time" name="hora_inicio_recargo_nocturno" required value="<?= View::e($actual['hora_inicio_recargo_nocturno'] ?? '21:00') ?>">
            </div>
            <div class="form-group">
                <label>Fin recargo nocturno</label>
                <input type="time" name="hora_fin_recargo_nocturno" required value="<?= View::e($actual['hora_fin_recargo_nocturno'] ?? '06:00') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="chk-almuerzo" name="almuerzo_activo" value="1" data-toggle="bloque-almuerzo" <?= !empty($actual['almuerzo_activo']) ? 'checked' : '' ?>>
                Descontar hora de almuerzo automaticamente
            </label>
            <small class="text-muted">Solo aplica a dias con un unico bloque continuo (sin turno partido); si el horario ya viene partido en dos bloques, ese hueco ya cuenta como almuerzo y no se descuenta de nuevo.</small>
        </div>
        <div id="bloque-almuerzo" class="form-row" style="<?= !empty($actual['almuerzo_activo']) ? '' : 'display:none' ?>">
            <div class="form-group">
                <label>Inicio del almuerzo</label>
                <input type="time" name="hora_inicio_almuerzo" value="<?= View::e($actual['hora_inicio_almuerzo'] ?? '12:00') ?>">
            </div>
            <div class="form-group">
                <label>Fin del almuerzo</label>
                <input type="time" name="hora_fin_almuerzo" value="<?= View::e($actual['hora_fin_almuerzo'] ?? '13:00') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Notas</label>
            <input type="text" name="notas" placeholder="Ej. actualizacion por reforma laboral">
        </div>
        <button type="submit" class="btn btn-primary">Guardar nueva vigencia</button>
    </form>
</div>

<div class="card">
    <h2>Historial</h2>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Vigente desde</th><th>Jornada</th><th>Recargo nocturno</th><th>Almuerzo</th><th>Notas</th></tr></thead>
        <tbody>
        <?php foreach ($historial as $h): ?>
            <tr>
                <td><?= View::e($h['vigente_desde']) ?></td>
                <td><?= View::e((string) $h['jornada_semanal_horas']) ?>h</td>
                <td><?= View::e($h['hora_inicio_recargo_nocturno']) ?> - <?= View::e($h['hora_fin_recargo_nocturno']) ?></td>
                <td><?= !empty($h['almuerzo_activo']) ? View::e($h['hora_inicio_almuerzo']) . ' - ' . View::e($h['hora_fin_almuerzo']) : '<span class="text-muted">Inactivo</span>' ?></td>
                <td><?= View::e($h['notas']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
