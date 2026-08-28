<?php use App\Core\Session; use App\Core\View; ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesion - <?= View::e($sitio['nombre_aplicacion']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-marca">
            <?php if (!empty($sitio['logo_path'])): ?>
                <img src="/uploads/logo/<?= View::e($sitio['logo_path']) ?>" alt="" class="login-logo">
            <?php endif; ?>
            <h1><?= View::e($sitio['nombre_aplicacion']) ?></h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">
            <div class="form-group">
                <label for="email">Correo</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Contrasena</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Ingresar</button>
        </form>

        <?php if (!empty($ms365Configurado)): ?>
            <div style="display:flex;align-items:center;gap:.75rem;margin:1.25rem 0;color:#9aa1ab;font-size:.8rem">
                <div style="flex:1;height:1px;background:#e2e5ea"></div>
                o
                <div style="flex:1;height:1px;background:#e2e5ea"></div>
            </div>
            <a href="/auth/microsoft" class="btn" style="width:100%;display:flex;align-items:center;justify-content:center;gap:.5rem;box-sizing:border-box">
                <svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true">
                    <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
                    <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
                    <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
                    <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
                </svg>
                Iniciar sesion con Microsoft
            </a>
        <?php endif; ?>
    </div>
    <?php if (!empty($sitio['footer_texto'])): ?>
        <footer class="login-footer"><?= nl2br(View::e($sitio['footer_texto'])) ?></footer>
    <?php endif; ?>
</div>
</body>
</html>
