<?php // app/Views/admin/calendario_ms365.php
use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2>Calendario Microsoft 365</h2>
    <p class="text-muted">
        Sincroniza turnos y novedades aprobadas hacia un calendario dedicado ("Horario de trabajo") en el Outlook
        de cada empleado. Usa un App Registration <strong>separado</strong> del de
        <a href="/admin/ms365">inicio de sesion (SSO)</a>: valores en
        <a href="https://portal.azure.com" target="_blank" rel="noopener">Azure Portal &rarr; Microsoft Entra ID &rarr; Registros de aplicaciones</a>
        con permiso de APLICACION <code>Calendars.ReadWrite</code>.
    </p>

    <?php $estado = !empty($config['tenant_id']) && !empty($config['client_id']) && !empty($config['client_secret']); ?>
    <p>
        Estado actual:
        <?= $estado ? '<span class="badge badge-aprobado">Configurado</span>' : '<span class="badge badge-pendiente">Incompleto</span>' ?>
        <?= !empty($config['activo']) ? '<span class="badge badge-aprobado">Activo</span>' : '<span class="badge badge-rechazado">Inactivo</span>' ?>
    </p>

    <form method="post" action="/admin/calendario-ms365">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

        <div class="form-group">
            <label>Tenant ID (Id. de directorio)</label>
            <input type="text" name="tenant_id" value="<?= View::e($config['tenant_id'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Client ID (Id. de aplicacion)</label>
            <input type="text" name="client_id" value="<?= View::e($config['client_id'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Client secret</label>
            <input type="password" name="client_secret" placeholder="<?= !empty($config['client_secret']) ? '•••••••••••••••••••••• (dejar vacio para conservar el actual)' : 'Pega aqui el valor del secreto' ?>" autocomplete="off">
        </div>

        <div class="form-group">
            <label><input type="checkbox" name="activo" value="1" <?= !empty($config['activo']) ? 'checked' : '' ?>> Sincronizacion activa</label>
            <small class="text-muted">Interruptor maestro: si esta apagado, no se sincroniza NINGUN empleado sin importar su interruptor individual.</small>
        </div>

        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>

<div class="card">
    <h2>Sincronizaciones con error</h2>
    <?php if (empty($errores)): ?>
        <p class="text-muted">No hay sincronizaciones pendientes de reintentar.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table>
            <thead><tr><th>Empleado</th><th>Tipo</th><th>Motivo</th><th>Ultimo intento</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($errores as $e): ?>
                <tr>
                    <td><?= View::e($e['empleado_nombre']) ?></td>
                    <td><?= $e['novedad_id'] ? 'Novedad (' . View::e($e['novedad_tipo_nombre'] ?? '') . ', ' . View::e($e['novedad_fecha'] ?? '') . ')' : 'Horario (' . View::e($e['horario_vigente_desde'] ?? '') . ')' ?></td>
                    <td><?= View::e($e['error_mensaje'] ?? '') ?></td>
                    <td><?= View::e($e['updated_at']) ?></td>
                    <td>
                        <form method="post" action="/admin/calendario-ms365/reintentar/<?= (int) $e['id'] ?>" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
                            <button type="submit" class="btn">Reintentar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
