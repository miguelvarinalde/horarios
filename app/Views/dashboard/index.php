<?php use App\Core\Auth; use App\Core\View; ?>
<div class="card">
    <h2>Bienvenido, <?= View::e($usuario['nombre'] ?? '') ?></h2>
    <p class="text-muted">Rol: <?= View::e($usuario['rol_nombre'] ?? '') ?></p>
    <p>Usa el menu de la izquierda para gestionar empleados, horarios, novedades, el calendario de equipo y los reportes de horas extra.</p>

    <?php if (Auth::puede('admin.configuracion')): ?>
        <p class="text-muted">Como Administrador, recuerda revisar la <a href="/admin/configuracion">configuracion de jornada y recargo nocturno</a> y los <a href="/admin/festivos">festivos</a> antes de calcular reportes.</p>
    <?php endif; ?>
</div>
