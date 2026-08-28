<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Nueva novedad</h2>

    <form method="post" action="/novedades">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <?php if ($empleadoFijo): ?>
            <input type="hidden" name="empleado_id" value="<?= (int) $empleadoFijo['id'] ?>">
            <p>Empleado: <strong><?= View::e($empleadoFijo['nombre']) ?></strong></p>
        <?php else: ?>
            <div class="form-group">
                <label>Empleado</label>
                <select name="empleado_id" required>
                    <?php foreach ($empleados as $e): ?>
                        <option value="<?= (int) $e['id'] ?>"><?= View::e($e['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Tipo de novedad</label>
            <select name="tipo_novedad_id" required>
                <?php foreach ($tipos as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= View::e($t['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="chk-rango-fechas" data-toggle="bloque-rango-fechas" data-toggle-ocultar="bloque-fecha-unica">
                Registrar por rango de fechas (varios dias seguidos, ej. vacaciones)
            </label>
        </div>

        <div id="bloque-fecha-unica" class="form-group">
            <label>Fecha</label>
            <input type="date" name="fecha">
        </div>

        <div id="bloque-rango-fechas" class="form-row" style="display:none">
            <div class="form-group">
                <label>Desde</label>
                <input type="date" name="fecha_inicio">
            </div>
            <div class="form-group">
                <label>Hasta</label>
                <input type="date" name="fecha_fin">
            </div>
        </div>
        <p class="text-muted" style="margin-top:-.5rem">Se creara una novedad independiente para cada dia del rango (queda pendiente de aprobacion igual que una novedad normal).</p>

        <div class="form-row">
            <div class="form-group">
                <label>Hora inicio (dejar vacio si es de dia completo)</label>
                <input type="time" name="hora_inicio">
            </div>
            <div class="form-group">
                <label>Hora fin</label>
                <input type="time" name="hora_fin">
            </div>
        </div>

        <div class="form-group">
            <label>Comentario / justificacion (opcional)</label>
            <textarea name="comentario" rows="3"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Registrar novedad</button>
        <a href="/novedades" class="btn">Cancelar</a>
    </form>
</div>
