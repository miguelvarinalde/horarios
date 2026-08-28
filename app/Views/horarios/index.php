<?php use App\Core\Auth; use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 class="mb-0">Horarios de <?= View::e($empleado['nombre']) ?></h2>
        <?php if (Auth::puede('horarios.crear')): ?>
            <a href="/empleados/<?= (int) $empleado['id'] ?>/horarios/crear" class="btn btn-primary">Nueva vigencia</a>
        <?php endif; ?>
    </div>

    <?php if (empty($vigencias)): ?>
        <p class="text-muted">Este empleado aun no tiene un horario base asignado.</p>
    <?php endif; ?>

    <?php foreach ($vigencias as $vigencia): ?>
        <div class="card" style="background:#fafafa">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <strong>Vigente desde <?= View::e($vigencia['vigente_desde']) ?><?= $vigencia['vigente_hasta'] ? ' hasta ' . View::e($vigencia['vigente_hasta']) : ' (indefinido)' ?></strong>
                <?php if (Auth::puede('horarios.editar')): ?>
                    <span style="white-space:nowrap">
                        <a href="/empleados/<?= (int) $empleado['id'] ?>/horarios/<?= View::e($vigencia['vigente_desde']) ?>/editar" class="btn btn-sm">Editar</a>
                        <form method="post" action="/empleados/<?= (int) $empleado['id'] ?>/horarios/eliminar" style="display:inline" data-confirm="¿Eliminar esta vigencia de horario?">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <input type="hidden" name="vigente_desde" value="<?= View::e($vigencia['vigente_desde']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                        </form>
                    </span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
            <table style="margin-top:.75rem">
                <thead><tr><th>Dia</th><th>Bloques (turnos)</th></tr></thead>
                <tbody>
                <?php foreach ($dias as $num => $nombre): ?>
                    <?php if (!isset($vigencia['dias'][$num])) continue; ?>
                    <tr>
                        <td><?= $nombre ?></td>
                        <td>
                            <?php foreach ($vigencia['dias'][$num]['bloques'] ?? [] as $b): ?>
                                <span class="badge badge-aprobado"><?= View::e($b['hora_inicio']) ?> - <?= View::e($b['hora_fin']) ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>

    <a href="/empleados" class="btn">Volver a empleados</a>
</div>
