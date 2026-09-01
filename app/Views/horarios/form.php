<?php
use App\Core\Session;
use App\Core\View;

// Los valores de BD vienen "HH:MM:SS"; el patron del input de texto (24h)
// solo acepta "HH:MM" (5 caracteres).
$hora5 = fn (?string $valor) => View::e($valor ? substr($valor, 0, 5) : '');
?>
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
                        <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="dia_<?= $num ?>_bloque1_inicio" style="width:5.5em" value="<?= $hora5($bloque1['hora_inicio'] ?? null) ?>">
                        -
                        <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="dia_<?= $num ?>_bloque1_fin" style="width:5.5em" value="<?= $hora5($bloque1['hora_fin'] ?? null) ?>">
                    </td>
                    <td>
                        <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="dia_<?= $num ?>_bloque2_inicio" style="width:5.5em" value="<?= $hora5($bloque2['hora_inicio'] ?? null) ?>">
                        -
                        <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="dia_<?= $num ?>_bloque2_fin" style="width:5.5em" value="<?= $hora5($bloque2['hora_fin'] ?? null) ?>">
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
