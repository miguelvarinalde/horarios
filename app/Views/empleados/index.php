<?php use App\Core\Auth; use App\Core\View; ?>
<?= View::renderRaw('partials/password_generada', ['passwordGenerada' => $passwordGenerada ?? null]) ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 class="mb-0">Empleados</h2>
        <?php if (Auth::puede('empleados.crear')): ?>
            <a href="/empleados/crear" class="btn btn-primary">Nuevo empleado</a>
        <?php endif; ?>
    </div>

    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Nombre</th>
            <th>Documento</th>
            <th>Cargo</th>
            <th>Area</th>
            <th>Supervisor</th>
            <th>Ingreso</th>
            <th>Estado</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($empleados as $e): ?>
            <tr>
                <td><?= View::e($e['nombre']) ?></td>
                <td><?= View::e($e['documento']) ?></td>
                <td><?= View::e($e['cargo']) ?></td>
                <td><?= $e['area_nombre'] ? View::e($e['area_nombre']) : '<span class="text-muted">Sin area</span>' ?></td>
                <td><?= View::e($e['supervisor_nombre'] ?? '-') ?></td>
                <td><?= View::e($e['fecha_ingreso']) ?></td>
                <td><?= $e['activo'] ? '<span class="badge badge-aprobado">Activo</span>' : '<span class="badge badge-rechazado">Inactivo</span>' ?></td>
                <td>
                    <a href="/empleados/<?= (int) $e['id'] ?>/horarios" class="btn btn-sm">Horarios</a>
                    <?php if (Auth::puede('empleados.editar')): ?>
                        <a href="/empleados/<?= (int) $e['id'] ?>/editar" class="btn btn-sm">Editar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($empleados)): ?>
            <tr><td colspan="8" class="text-muted">Aun no hay empleados registrados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
