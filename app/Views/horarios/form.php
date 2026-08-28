<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2><?= $vigencia ? 'Editar vigencia de horario' : 'Nueva vigencia de horario' ?> - <?= View::e($empleado['nombre']) ?></h2>
    <p class="text-muted">Marca los dias en que el empleado trabaja. Puedes definir hasta dos bloques por dia para turnos partidos (ej. 08:00-12:00 y 14:00-18:00).</p>

    <form method="post" action="<?= $vigencia
        ? "/empleados/{$empleado['id']}/horarios/" . View::e($vigencia['vigente_desde']) . '/editar'
        : "/empleados/{$empleado['id']}/horarios" ?>">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <div class="form-row">
            <div class="form-group">
                <label>Vigente desde</label>
                <?php if ($vigencia): ?>
                    <input type="date" value="<?= View::e($vigencia['vigente_desde']) ?>" disabled>
                    <small class="text-muted">La fecha de inicio de una vigencia no se puede cambiar; si necesitas otra fecha, crea una vigencia nueva.</small>
                <?php else: ?>
                    <input type="date" name="vigente_desde" required>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Vigente hasta (opcional)</label>
                <input type="date" name="vigente_hasta" value="<?= View::e($vigencia['vigente_hasta'] ?? '') ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Comentario (opcional)</label>
            <input type="text" name="comentario" placeholder="Ej. turno especial por proyecto X" value="<?= View::e($vigencia['dias'][array_key_first($vigencia['dias'] ?? [])]['comentario'] ?? '') ?>">
        </div>

        <div class="table-responsive">
        <table>
            <thead>
            <tr><th></th><th>Dia</th><th>Bloque 1</th><th>Bloque 2 (turno partido)</th></tr>
            </thead>
            <tbody>
            <?php foreach ($dias as $num => $nombre): ?>
                <?php
                    $diaVigencia = $vigencia['dias'][$num] ?? null;
                    $bloque1 = $diaVigencia['bloques'][0] ?? null;
                    $bloque2 = $diaVigencia['bloques'][1] ?? null;
                ?>
                <tr>
                    <td><input type="checkbox" name="dia_<?= $num ?>_activo" value="1" <?= $diaVigencia ? 'checked' : '' ?>></td>
                    <td><?= $nombre ?></td>
                    <td>
                        <input type="time" name="dia_<?= $num ?>_bloque1_inicio" style="width:auto" value="<?= View::e($bloque1['hora_inicio'] ?? '') ?>">
                        -
                        <input type="time" name="dia_<?= $num ?>_bloque1_fin" style="width:auto" value="<?= View::e($bloque1['hora_fin'] ?? '') ?>">
                    </td>
                    <td>
                        <input type="time" name="dia_<?= $num ?>_bloque2_inicio" style="width:auto" value="<?= View::e($bloque2['hora_inicio'] ?? '') ?>">
                        -
                        <input type="time" name="dia_<?= $num ?>_bloque2_fin" style="width:auto" value="<?= View::e($bloque2['hora_fin'] ?? '') ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-primary"><?= $vigencia ? 'Guardar cambios' : 'Guardar vigencia' ?></button>
            <a href="/empleados/<?= (int) $empleado['id'] ?>/horarios" class="btn">Cancelar</a>
        </div>
    </form>
</div>
