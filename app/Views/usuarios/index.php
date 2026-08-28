<?php use App\Core\Session; use App\Core\View; ?>
<?= View::renderRaw('partials/password_generada', ['passwordGenerada' => $passwordGenerada ?? null]) ?>
<div class="card">
    <h2>Usuarios</h2>
    <p class="text-muted">Si un empleado no conoce (o perdio) su contrasena, resetéala aqui: se genera una nueva que debera cambiar en su proximo inicio de sesion.</p>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Ultimo login</th>
            <th>Debe cambiar clave</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= View::e($u['nombre']) ?></td>
                <td><?= View::e($u['email']) ?></td>
                <td><?= View::e($u['rol_nombre']) ?></td>
                <td><?= $u['activo'] ? '<span class="badge badge-aprobado">Activo</span>' : '<span class="badge badge-rechazado">Inactivo</span>' ?></td>
                <td><?= $u['ultimo_login_at'] ? View::e($u['ultimo_login_at']) : '<span class="text-muted">Nunca</span>' ?></td>
                <td><?= $u['debe_cambiar_password'] ? '<span class="badge badge-pendiente">Si</span>' : '-' ?></td>
                <td>
                    <form method="post" action="/usuarios/<?= (int) $u['id'] ?>/resetear-password" data-confirm="¿Resetear la contrasena de <?= View::e($u['nombre']) ?>? La contrasena actual dejara de funcionar.">
                        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                        <button type="submit" class="btn btn-sm">Resetear contrasena</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($usuarios)): ?>
            <tr><td colspan="7" class="text-muted">No hay usuarios registrados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
