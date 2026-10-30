<?php
use App\Core\Session;
use App\Core\View;

// Los valores de BD vienen "HH:MM:SS"; el patron del input de texto (24h)
// solo acepta "HH:MM" (5 caracteres) — sin este recorte, precargar el
// formulario dejaria un valor que no matchea su propio patron.
$hora5 = fn (?string $valor, string $porDefecto) => View::e($valor ? substr($valor, 0, 5) : $porDefecto);
?>
<div class="card">
    <h2>Jornada semanal y recargo nocturno</h2>
    <p class="text-muted">Configuracion actualmente vigente:</p>
    <?php if ($actual): ?>
        <ul>
            <li>Jornada semanal: <strong><?= View::e((string) $actual['jornada_semanal_horas']) ?> horas</strong></li>
            <li>
                Tope de horas ordinarias por dia:
                <?php if (!empty($actual['jornada_diaria_maxima_horas'])): ?>
                    <strong><?= View::e((string) (float) $actual['jornada_diaria_maxima_horas']) ?> horas</strong> (lo que pase de ahi en un mismo dia es hora extra)
                <?php else: ?>
                    <span class="text-muted">Sin tope diario (solo cuenta la semana)</span>
                <?php endif; ?>
            </li>
            <li>
                Redondeo de marcaciones (informes por registro):
                <?php if ((int) ($actual['redondeo_minutos'] ?? 0) > 0): ?>
                    <strong>a <?= (int) $actual['redondeo_minutos'] ?> minutos</strong>
                <?php else: ?>
                    <span class="text-muted">Sin redondeo</span>
                <?php endif; ?>
            </li>
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
                <label>Tope de horas ordinarias por dia</label>
                <input type="number" step="0.25" min="1" max="24" name="jornada_diaria_maxima_horas" placeholder="Vacio = sin tope diario" value="<?= View::e(isset($actual['jornada_diaria_maxima_horas']) && $actual['jornada_diaria_maxima_horas'] !== null ? (string) (float) $actual['jornada_diaria_maxima_horas'] : '9') ?>">
                <small class="text-muted">Jornada flexible: hasta 9 horas ordinarias por dia sin recargo, siempre que la semana no pase de la jornada semanal. Las horas que superen este tope en un mismo dia se pagan como extra.</small>
            </div>
            <div class="form-group">
                <label>Redondeo de marcaciones</label>
                <?php $redondeoActual = (string) ($actual['redondeo_minutos'] ?? 15); ?>
                <select name="redondeo_minutos">
                    <?php foreach (['0' => 'Sin redondeo', '5' => '5 minutos', '10' => '10 minutos', '15' => '15 minutos', '20' => '20 minutos', '30' => '30 minutos'] as $valor => $etiqueta): ?>
                        <option value="<?= $valor ?>" <?= $redondeoActual === (string) $valor ? 'selected' : '' ?>><?= $etiqueta ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Cada entrada y salida se lleva al multiplo mas cercano; en un empate exacto la entrada baja y la salida sube (a favor del colaborador). Solo afecta las columnas "redondeado" de Horas trabajadas segun registro.</small>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Inicio recargo nocturno</label>
                <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="hora_inicio_recargo_nocturno" required value="<?= $hora5($actual['hora_inicio_recargo_nocturno'] ?? null, '21:00') ?>">
            </div>
            <div class="form-group">
                <label>Fin recargo nocturno</label>
                <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="hora_fin_recargo_nocturno" required value="<?= $hora5($actual['hora_fin_recargo_nocturno'] ?? null, '06:00') ?>">
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
                <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="hora_inicio_almuerzo" value="<?= $hora5($actual['hora_inicio_almuerzo'] ?? null, '12:00') ?>">
            </div>
            <div class="form-group">
                <label>Fin del almuerzo</label>
                <input type="text" inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="HH:MM" maxlength="5" title="Formato de 24 horas, ej. 14:30" name="hora_fin_almuerzo" value="<?= $hora5($actual['hora_fin_almuerzo'] ?? null, '13:00') ?>">
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
        <thead><tr><th>Vigente desde</th><th>Jornada</th><th>Tope diario</th><th>Redondeo</th><th>Recargo nocturno</th><th>Almuerzo</th><th>Notas</th></tr></thead>
        <tbody>
        <?php foreach ($historial as $h): ?>
            <tr>
                <td><?= View::e($h['vigente_desde']) ?></td>
                <td><?= View::e((string) $h['jornada_semanal_horas']) ?>h</td>
                <td><?= !empty($h['jornada_diaria_maxima_horas']) ? View::e((string) (float) $h['jornada_diaria_maxima_horas']) . 'h' : '<span class="text-muted">&mdash;</span>' ?></td>
                <td><?= (int) ($h['redondeo_minutos'] ?? 0) > 0 ? (int) $h['redondeo_minutos'] . ' min' : '<span class="text-muted">Sin redondeo</span>' ?></td>
                <td><?= View::e($h['hora_inicio_recargo_nocturno']) ?> - <?= View::e($h['hora_fin_recargo_nocturno']) ?></td>
                <td><?= !empty($h['almuerzo_activo']) ? View::e($h['hora_inicio_almuerzo']) . ' - ' . View::e($h['hora_fin_almuerzo']) : '<span class="text-muted">Inactivo</span>' ?></td>
                <td><?= View::e($h['notas']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
