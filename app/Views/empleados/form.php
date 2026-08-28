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
