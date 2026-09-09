<?php
use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

// Por debajo de esto, la precision del GPS/red del dispositivo es tan mala
// (cientos/miles de metros) que la marcacion "capturada" no sirve para
// verificar el lugar real — se avisa en vez de mostrarla igual que una
// lectura confiable (mismo umbral que marcar-tiempo.js, 2026-09-08).
$precisionBajaDesde = 150;
?>
<div class="card">
    <h2>Registros de entrada y salida</h2>

    <form method="get" action="/registros-tiempo" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:1rem">
        <div class="form-group mb-0">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= View::e($desde ?? '') ?>">
        </div>
        <div class="form-group mb-0">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= View::e($hasta ?? '') ?>">
        </div>
        <button type="submit" class="btn">Filtrar</button>
    </form>

    <?php if (Auth::puede('registros_tiempo.corregir')): ?>
        <a href="/registros-tiempo/crear" class="btn btn-primary" style="margin-bottom:1rem;display:inline-block">Agregar marcacion manual</a>
    <?php endif; ?>

    <div class="table-responsive">
    <table>
        <thead><tr><th>Empleado</th><th>Tipo</th><th>Fecha y hora (servidor)</th><th>Precision</th><th>Ubicacion</th><th>Observaciones</th><?php if (Auth::puede('registros_tiempo.corregir')): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><?= View::e($r['empleado_nombre']) ?></td>
                <td><?= $r['tipo'] === 'entrada' ? '<span class="badge badge-aprobado">Entrada</span>' : '<span class="badge badge-pendiente">Salida</span>' ?></td>
                <td><?= View::e($r['fecha_hora']) ?></td>
                <td>
                    <?php if ($r['precision_metros'] !== null): ?>
                        &plusmn;<?= round((float) $r['precision_metros']) ?>m
                        <?php if ((float) $r['precision_metros'] > $precisionBajaDesde): ?>
                            <span class="badge badge-pendiente" title="Probablemente no representa el lugar real de la marcacion (ubicacion aproximada por wifi/antenas en vez de un GPS con buena senal).">Baja precision</span>
                        <?php endif; ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['ubicacion_estado'] === 'capturada' && $r['latitud'] !== null): ?>
                        <a href="https://www.google.com/maps?q=<?= View::e((string) $r['latitud']) ?>,<?= View::e((string) $r['longitud']) ?>" target="_blank" rel="noopener">Ver mapa</a>
                    <?php else: ?>
                        <span class="text-muted"><?= View::e($r['ubicacion_estado']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $r['comentario'] ? View::e($r['comentario']) : '<span class="text-muted">&mdash;</span>' ?></td>
                <?php if (Auth::puede('registros_tiempo.corregir')): ?>
                    <td style="white-space:nowrap">
                        <a href="/registros-tiempo/<?= (int) $r['id'] ?>/editar" class="btn btn-sm">Editar</a>
                        <form method="post" action="/registros-tiempo/<?= (int) $r['id'] ?>/eliminar" style="display:inline" data-confirm="¿Eliminar esta marcacion? Esta accion no se puede deshacer.">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                        </form>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($registros)): ?>
            <tr><td colspan="7" class="text-muted">No hay registros para los filtros seleccionados.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
