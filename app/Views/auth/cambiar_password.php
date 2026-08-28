<?php use App\Core\Session; use App\Core\View; ?>
<?php
$formulario = function () use ($error) { ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/cambiar-password">
        <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
        <div class="form-group">
            <label for="password_actual">Contrasena actual</label>
            <input type="password" id="password_actual" name="password_actual" required autofocus>
        </div>
        <div class="form-group">
            <label for="password_nueva">Nueva contrasena (minimo 8 caracteres)</label>
            <input type="password" id="password_nueva" name="password_nueva" minlength="8" required>
        </div>
        <div class="form-group">
            <label for="password_confirmacion">Confirmar nueva contrasena</label>
            <input type="password" id="password_confirmacion" name="password_confirmacion" minlength="8" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Cambiar contrasena</button>
    </form>
<?php };
?>

<?php if ($obligatorio): ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cambiar contrasena - Sistema de Horarios</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <h1>Debes cambiar tu contrasena</h1>
        <p class="text-muted" style="margin-top:-.5rem">
            Es tu primer ingreso, o tu contrasena fue reseteada. Ingresa la contrasena temporal que te dieron
            y elige una nueva antes de continuar.
        </p>
        <?php $formulario(); ?>
    </div>
</div>
</body>
</html>
<?php else: ?>
<div class="card" style="max-width:420px">
    <h2>Cambiar mi contrasena</h2>
    <?php $formulario(); ?>
</div>
<?php endif; ?>
