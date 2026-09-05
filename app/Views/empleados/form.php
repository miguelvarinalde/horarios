<?php use App\Core\Session; use App\Core\View; ?>
<div class="card">
    <h2><?= $empleado ? 'Editar empleado' : 'Nuevo empleado' ?></h2>

    <form method="post" action="<?= $empleado ? "/empleados/{$empleado['id']}" : '/empleados' ?>">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <?php if ($empleado): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Nombre completo</label>
                <input type="text" name="nombre" required value="<?= View::e($empleado['nombre'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Documento</label>
                <input type="text" name="documento" required value="<?= View::e($empleado['documento'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Cargo</label>
                <input type="text" name="cargo" value="<?= View::e($empleado['cargo'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Fecha de ingreso</label>
                <input type="date" name="fecha_ingreso" required value="<?= View::e($empleado['fecha_ingreso'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Area / equipo</label>
                <select name="area_id">
                    <option value="">-- Sin area asignada --</option>
                    <?php foreach ($areas as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= (($empleado['area_id'] ?? null) == $a['id']) ? 'selected' : '' ?>><?= View::e($a['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Define que puede ver/gestionar un Supervisor de esta area (horarios, novedades, calendario, reportes, etc.). Se administra en <a href="/admin/areas">Areas</a>.</small>
            </div>
            <div class="form-group">
                <label>Supervisor / jefe directo (opcional)</label>
                <select name="supervisor_id">
                    <option value="">-- Sin supervisor --</option>
                    <?php foreach ($supervisores as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (($empleado['supervisor_id'] ?? null) == $s['id']) ? 'selected' : '' ?>><?= View::e($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Solo informativo (organigrama) — no controla que puede ver ni gestionar este supervisor. Eso lo define el Area.</small>
            </div>
        </div>

        <?php if ($empleado && !empty($empleado['usuario_id'])): ?>
            <div class="form-group">
                <label>Correo de la cuenta de acceso</label>
                <input type="email" name="email" required value="<?= View::e($usuarioEmail ?? '') ?>">
                <small class="text-muted">Correo con el que este usuario inicia sesion. Cambialo aqui si se equivoco al digitarlo o si la persona cambio de correo.</small>
            </div>

            <div class="form-group">
                <label>Areas adicionales que puede supervisar</label>
                <div style="max-height:12rem;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:.5rem .75rem">
                    <?php if (empty($areas)): ?>
                        <span class="text-muted">No hay areas activas creadas.</span>
                    <?php endif; ?>
                    <?php foreach ($areas as $a): ?>
                        <label style="display:flex;align-items:center;gap:.5rem;font-weight:normal;padding:.2rem 0">
                            <input type="checkbox" name="areas_adicionales[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $areaIdsAdicionales, true) ? 'checked' : '' ?>>
                            <?= View::e($a['nombre']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">Ademas de su propia area (arriba), este usuario vera/gestionara tambien estas areas — util cuando un mismo supervisor cubre varios departamentos. Solo aplica si no tiene el permiso "Ver todas las areas" (ese permiso ya le da acceso a todo).</small>
            </div>
        <?php endif; ?>

        <?php if ($empleado): ?>
            <div class="form-group">
                <label><input type="checkbox" name="activo" value="1" <?= $empleado['activo'] ? 'checked' : '' ?>> Empleado activo</label>
            </div>
        <?php else: ?>
            <div class="card" style="background:#f9fafb">
                <label><input type="checkbox" name="crear_usuario" value="1" data-toggle="camposUsuario"> Crear cuenta de acceso al sistema para este empleado</label>
                <div id="camposUsuario" style="display:none;margin-top:1rem">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Correo</label>
                            <input type="email" name="email">
                        </div>
                        <div class="form-group">
                            <label>Rol</label>
                            <select name="rol_id">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= View::e($r['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Contrasena inicial (el usuario debera cambiarla)</label>
                        <input type="text" name="password" placeholder="Dejar vacio para generar una aleatoria">
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="/empleados" class="btn">Cancelar</a>
    </form>
</div>
