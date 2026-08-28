<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Tipos de novedad</h2>
    <div class="table-responsive">
    <table>
        <thead><tr><th>Codigo</th><th>Nombre</th><th>Categoria</th><th>Requiere aprobacion</th><th>Afecta pago</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tipos as $t): ?>
            <tr>
                <td><?= View::e($t['codigo']) ?></td>
                <td><?= View::e($t['nombre']) ?></td>
                <td><?= View::e($t['categoria']) ?></td>
                <td><?= $t['requiere_aprobacion'] ? 'Si' : 'No' ?></td>
                <td><?= $t['afecta_pago'] ? 'Si' : 'No' ?></td>
                <td><?= $t['activo'] ? '<span class="badge badge-aprobado">Activo</span>' : '<span class="badge badge-rechazado">Inactivo</span>' ?></td>
                <td>
                    <form method="post" action="/admin/tipos-novedad/<?= (int) $t['id'] ?>/alternar">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <button type="submit" class="btn btn-sm"><?= $t['activo'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <h2>Nuevo tipo de novedad</h2>
    <form method="post" action="/admin/tipos-novedad">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Codigo</label>
                <input type="text" name="codigo" required placeholder="Ej. LICENCIA_LUTO">
            </div>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required>
            </div>
        </div>
        <div class="form-group">
            <label>Categoria</label>
            <select name="categoria" required>
                <option value="permiso">Permiso</option>
                <option value="vacaciones">Vacaciones</option>
                <option value="incapacidad">Incapacidad</option>
                <option value="hora_extra">Hora extra</option>
                <option value="ausencia">Ausencia</option>
                <option value="festivo_trabajado">Festivo trabajado</option>
                <option value="descanso_compensatorio">Descanso compensatorio</option>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group"><label><input type="checkbox" name="requiere_aprobacion" value="1" checked> Requiere aprobacion</label></div>
            <div class="form-group"><label><input type="checkbox" name="afecta_pago" value="1" checked> Afecta pago/calculo</label></div>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
