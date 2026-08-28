<?php
/** @var array{usuario:string,email:string,password:string}|null $passwordGenerada */
use App\Core\View;
?>
<?php if (!empty($passwordGenerada)): ?>
    <div class="alert alert-success" style="border:2px solid var(--color-success, #16a34a)">
        <strong>Contrasena temporal para <?= View::e($passwordGenerada['usuario']) ?> (<?= View::e($passwordGenerada['email']) ?>):</strong>
        <div style="font-family:monospace;font-size:1.1rem;background:#fff;border:1px dashed #999;border-radius:4px;padding:.5rem .75rem;margin:.5rem 0;display:inline-block">
            <?= View::e($passwordGenerada['password']) ?>
        </div>
        <p class="mb-0" style="margin-top:.35rem">
            Copiala ahora y entregasela de forma segura — no se volvera a mostrar. Debera cambiarla al iniciar sesion por primera vez.
        </p>
    </div>
<?php endif; ?>
