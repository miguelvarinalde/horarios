<?php use App\Core\View; ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h2 class="mb-0">Calendario de equipo</h2>
        <div>
            <a href="/calendario?semana=<?= $semanaAnteriorAncla ?>" class="btn btn-sm">&larr; Semana anterior</a>
            <a href="/calendario?semana=<?= $semanaSiguienteAncla ?>" class="btn btn-sm">Semana siguiente &rarr;</a>
        </div>
    </div>

    <div style="overflow-x:auto">
    <div class="table-responsive">
    <table>
        <thead>
        <tr>
            <th>Empleado</th>
            <?php foreach ($dias as $num => $nombre): ?>
                <th><?= $nombre ?><br><span class="text-muted"><?= View::e($semana[$num]) ?></span></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($datos as $registro): ?>
            <tr>
                <td><strong><?= View::e($registro['empleado']['nombre']) ?></strong></td>
                <?php foreach ($dias as $num => $nombre): ?>
                    <?php $dia = $registro['dias'][$num]; ?>
                    <td>
                        <?php foreach ($dia['bloques'] as $b): ?>
                            <div class="badge badge-aprobado" style="display:block;margin-bottom:2px"><?= View::e($b['hora_inicio']) ?>-<?= View::e($b['hora_fin']) ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($dia['novedades'] as $n): ?>
                            <div class="badge badge-pendiente" style="display:block;margin-bottom:2px"><?= View::e($n['tipo_nombre']) ?></div>
                        <?php endforeach; ?>
                        <?php if (empty($dia['bloques']) && empty($dia['novedades'])): ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($datos)): ?>
            <tr><td colspan="8" class="text-muted">No hay empleados para mostrar.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
