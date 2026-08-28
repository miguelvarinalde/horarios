<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Microsoft 365 / Entra ID (SSO)</h2>
    <p class="text-muted">
        Estos valores vienen del registro de la aplicacion en
        <a href="https://portal.azure.com" target="_blank" rel="noopener">Azure Portal &rarr; Microsoft Entra ID &rarr; Registros de aplicaciones</a>.
        El boton "Iniciar sesion con Microsoft" solo aparece en la pantalla de login cuando los 4 campos estan completos.
    </p>

    <?php $estado = !empty($config['tenant_id']) && !empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['redirect_uri']); ?>
    <p>
        Estado actual:
        <?= $estado ? '<span class="badge badge-aprobado">Configurado</span>' : '<span class="badge badge-pendiente">Incompleto</span>' ?>
    </p>

    <form method="post" action="/admin/ms365">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <div class="form-group">
            <label>Tenant ID (Id. de directorio)</label>
            <input type="text" name="tenant_id" value="<?= View::e($config['tenant_id'] ?? '') ?>" placeholder="ej. 90519e26-8bb9-4a89-b21e-b3227b083ba5">
        </div>

        <div class="form-group">
            <label>Client ID (Id. de aplicacion)</label>
            <input type="text" name="client_id" value="<?= View::e($config['client_id'] ?? '') ?>" placeholder="ej. f5fd74ad-fb0a-4e03-adfb-a542312038de">
        </div>

        <div class="form-group">
            <label>Client secret</label>
            <input type="password" name="client_secret" placeholder="<?= !empty($config['client_secret']) ? '•••••••••••••••••••••• (dejar vacio para conservar el actual)' : 'Pega aqui el valor del secreto' ?>" autocomplete="off">
            <?php if (!empty($config['client_secret'])): ?>
                <small class="text-muted">Ya hay un secreto guardado. Solo escribe uno nuevo si vas a reemplazarlo (ej. rotacion periodica).</small>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Redirect URI</label>
            <input type="text" name="redirect_uri" value="<?= View::e($config['redirect_uri'] ?? '') ?>" placeholder="ej. https://horarios.test/auth/microsoft/callback">
            <small class="text-muted">Debe coincidir exactamente (letra por letra) con el URI de redireccion registrado en Azure Portal.</small>
        </div>

        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
