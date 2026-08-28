<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2><?= $registro ? 'Corregir marcacion' : 'Agregar marcacion manual' ?></h2>
    <p class="text-muted">
        <?php if ($registro): ?>
            Estas corrigiendo una marcacion existente de <strong><?= View::e($registro['empleado_nombre']) ?></strong>. Explica el motivo de la correccion: queda registrado como observacion.
        <?php else: ?>
            Usa esto solo cuando a alguien se le olvido marcar (ej. no marco salida y por eso su siguiente marcacion quedo mal etiquetada). Explica el motivo: queda registrado como observacion.
        <?php endif; ?>
    </p>

    <form method="post" action="<?= $registro ? "/registros-tiempo/{$registro['id']}/editar" : '/registros-tiempo/crear' ?>">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <?php if ($registro): ?>
            <input type="hidden" name="empleado_id" value="<?= (int) $registro['empleado_id'] ?>">
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
            <label>Tipo</label>
            <select name="tipo" required>
                <option value="entrada" <?= ($registro && $registro['tipo'] === 'entrada') ? 'selected' : '' ?>>Entrada</option>
                <option value="salida" <?= (!$registro || $registro['tipo'] === 'salida') ? 'selected' : '' ?>>Salida</option>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Fecha</label>
                <input type="date" name="fecha" required value="<?= $registro ? View::e(substr($registro['fecha_hora'], 0, 10)) : '' ?>">
            </div>
            <div class="form-group">
                <label>Hora</label>
                <input type="time" name="hora" required value="<?= $registro ? View::e(substr($registro['fecha_hora'], 11, 5)) : '' ?>">
            </div>
        </div>

        <div class="form-group">
            <label>Motivo / observaciones</label>
            <textarea name="comentario" rows="3" required placeholder="Ej. El empleado olvido marcar salida el dia anterior; se agrega con base en su reporte verbal."><?= $registro ? View::e($registro['comentario'] ?? '') : '' ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary"><?= $registro ? 'Guardar correccion' : 'Agregar marcacion' ?></button>
        <a href="/registros-tiempo" class="btn">Cancelar</a>
    </form>
</div>
