<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Porcentajes de recargo</h2>
    <p class="text-muted">El motor de calculo busca, para cada franja de hora trabajada, la fila cuyos indicadores (extra / nocturno / dominical-festivo) coincidan y aplica ese porcentaje. Para cambiar un porcentaje por una reforma legal, registra una fila nueva con la fecha de vigencia correspondiente; no se edita el historico.</p>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Codigo</th><th>Nombre</th><th>Extra</th><th>Nocturno</th><th>Dom/Festivo</th><th>%</th><th>Vigente desde</th></tr></thead>
        <tbody>
        <?php foreach ($tipos as $t): ?>
            <tr>
                <td><strong><?= View::e($t['codigo']) ?></strong></td>
                <td><?= View::e($t['nombre']) ?></td>
                <td><?= $t['es_hora_extra'] ? 'Si' : '-' ?></td>
                <td><?= $t['es_nocturno'] ? 'Si' : '-' ?></td>
                <td><?= $t['es_dominical_festivo'] ? 'Si' : '-' ?></td>
                <td><?= View::e((string) $t['porcentaje']) ?>%</td>
                <td><?= View::e($t['vigente_desde']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Nueva fila de recargo</h2>
    <form method="post" action="/admin/tipos-recargo">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Codigo</label>
                <input type="text" name="codigo" required placeholder="Ej. HED">
            </div>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required placeholder="Ej. Hora extra diurna">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group"><label><input type="checkbox" name="es_hora_extra" value="1"> Es hora extra</label></div>
            <div class="form-group"><label><input type="checkbox" name="es_nocturno" value="1"> Es nocturno</label></div>
            <div class="form-group"><label><input type="checkbox" name="es_dominical_festivo" value="1"> Es dominical/festivo</label></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Porcentaje</label>
                <input type="number" step="0.01" name="porcentaje" required>
            </div>
            <div class="form-group">
                <label>Vigente desde</label>
                <input type="date" name="vigente_desde" required>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
