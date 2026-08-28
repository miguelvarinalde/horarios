<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Personalizacion del sitio</h2>
    <p class="text-muted">Nombre y logo del encabezado, y texto del pie de pagina, visibles en todo el sitio (incluida la pantalla de inicio de sesion).</p>

    <form method="post" action="/admin/sitio">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <div class="form-group">
            <label>Nombre de la aplicacion</label>
            <input type="text" name="nombre_aplicacion" value="<?= View::e($sitio['nombre_aplicacion'] ?? '') ?>" maxlength="120" required>
        </div>

        <div class="form-group">
            <label>Texto del pie de pagina (opcional)</label>
            <textarea name="footer_texto" rows="3" maxlength="500" placeholder="Ej. INALDE Business School &#10;soporte@inalde.edu.co"><?= View::e($sitio['footer_texto'] ?? '') ?></textarea>
            <small class="text-muted">Solo texto plano (sin HTML). Los saltos de linea se conservan.</small>
        </div>

        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>

<div class="card">
    <h2>Logo</h2>

    <?php if (!empty($sitio['logo_path'])): ?>
        <p>
            <img src="/uploads/logo/<?= View::e($sitio['logo_path']) ?>" alt="Logo actual" style="max-height:60px;max-width:220px;object-fit:contain;display:block;margin-bottom:.75rem">
        </p>
        <form method="post" action="/admin/sitio/logo/eliminar" data-confirm="¿Eliminar el logo actual? Volvera a mostrarse solo el nombre de la aplicacion.">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <button type="submit" class="btn btn-danger btn-sm">Eliminar logo</button>
        </form>
        <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--color-border)">
    <?php else: ?>
        <p class="text-muted">No hay ningun logo cargado todavia. Se muestra solo el nombre de la aplicacion.</p>
    <?php endif; ?>

    <form method="post" action="/admin/sitio/logo" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-group">
            <label><?= !empty($sitio['logo_path']) ? 'Reemplazar logo' : 'Subir logo' ?></label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required>
            <small class="text-muted">PNG, JPG, GIF o WEBP. Maximo 2 MB.</small>
        </div>
        <button type="submit" class="btn btn-primary">Subir</button>
    </form>
</div>
