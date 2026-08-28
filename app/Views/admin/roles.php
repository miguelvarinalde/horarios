<?php use App\Core\Session; use App\Core\View; use App\Models\RolModel; ?>
<div class="card">
    <h2>Roles</h2>
    <?php foreach ($roles as $rol): ?>
        <?php $permisosDelRol = array_column(RolModel::permisos((int) $rol['id']), 'id'); ?>
        <div class="card" style="background:#fafafa">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <strong><?= View::e($rol['nombre']) ?></strong>
                <?php if (!$rol['es_sistema']): ?>
                    <form method="post" action="/admin/roles/<?= (int) $rol['id'] ?>/eliminar" data-confirm="¿Eliminar este rol?">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Eliminar rol</button>
                    </form>
                <?php else: ?>
                    <span class="badge badge-aprobado">Rol base del sistema</span>
                <?php endif; ?>
            </div>
            <p class="text-muted"><?= View::e($rol['descripcion']) ?></p>

            <form method="post" action="/admin/roles/<?= (int) $rol['id'] ?>/permisos">
                <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                <?php foreach ($permisosAgrupados as $modulo => $permisos): ?>
                    <div style="margin-bottom:.5rem">
                        <strong style="text-transform:capitalize"><?= View::e($modulo) ?></strong><br>
                        <?php foreach ($permisos as $p): ?>
                            <label style="margin-right:1rem;font-weight:normal">
                                <input type="checkbox" name="permisos[]" value="<?= (int) $p['id'] ?>" <?= in_array($p['id'], $permisosDelRol) ? 'checked' : '' ?>>
                                <?= View::e($p['nombre']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary btn-sm">Guardar permisos</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>Nuevo rol</h2>
    <form method="post" action="/admin/roles">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" required>
            </div>
            <div class="form-group">
                <label>Descripcion</label>
                <input type="text" name="descripcion">
            </div>
        </div>
        <?php foreach ($permisosAgrupados as $modulo => $permisos): ?>
            <div style="margin-bottom:.5rem">
                <strong style="text-transform:capitalize"><?= View::e($modulo) ?></strong><br>
                <?php foreach ($permisos as $p): ?>
                    <label style="margin-right:1rem;font-weight:normal">
                        <input type="checkbox" name="permisos[]" value="<?= (int) $p['id'] ?>">
                        <?= View::e($p['nombre']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">Crear rol</button>
    </form>
</div>
