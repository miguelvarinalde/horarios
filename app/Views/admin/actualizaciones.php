<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Actualizaciones de la base de datos</h2>
    <p class="text-muted">
        Despues de subir codigo nuevo por FTP/Administrador de archivos, aplica aqui los cambios de base de
        datos que traiga — no hace falta Terminal. Un solo boton aplica tanto las migraciones (cambios de
        estructura) como los catalogos base (roles, permisos, tipos de novedad/recargo): siempre es seguro
        volver a correrlo, aunque no haya nada pendiente.
    </p>

    <?php if (!empty($resultado)): ?>
        <div class="card" style="background:#f0fdf4;border:1px solid var(--color-success,#16a34a)">
            <strong>Ultima actualizacion aplicada:</strong>
            <?php if (!empty($resultado['migraciones'])): ?>
                <p class="mb-0" style="margin-top:.5rem">Migraciones nuevas:</p>
                <ul class="plain" style="margin:.25rem 0 0">
                    <?php foreach ($resultado['migraciones'] as $m): ?>
                        <li><code class="inline"><?= View::e($m) ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="mb-0" style="margin-top:.5rem">No habia migraciones nuevas.</p>
            <?php endif; ?>
            <p class="mb-0" style="margin-top:.5rem">
                Catalogos base (roles/permisos/tipos de novedad/tipos de recargo/configuracion) revisados y
                actualizados: <?= count($resultado['seeds'] ?? []) ?> archivo(s).
            </p>
        </div>
    <?php endif; ?>

    <?php if (empty($pendientes)): ?>
        <p><span class="badge badge-aprobado">Estructura al dia</span> No hay migraciones de estructura pendientes.</p>
    <?php else: ?>
        <p><span class="badge badge-pendiente">Pendientes</span> Hay <?= count($pendientes) ?> migracion(es) de estructura sin aplicar:</p>
        <ul class="plain">
            <?php foreach ($pendientes as $p): ?>
                <li><code class="inline"><?= View::e($p) ?></code></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="/admin/actualizaciones" data-confirm="¿Aplicar la actualizacion? Esto migra la estructura y refresca los catalogos base de la base de datos.">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <button type="submit" class="btn btn-primary">Aplicar actualizacion (migraciones + catalogos)</button>
    </form>
</div>
