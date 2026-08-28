<?php use App\Core\Session; use App\Core\View; ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalacion - Sistema de Horarios</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box" style="max-width:480px">
        <h1>Configurar el sistema</h1>
        <p class="text-muted" style="margin-top:-.5rem;margin-bottom:1.25rem">
            Primera vez que se ejecuta: conecta la base de datos y crea el usuario Administrador.
            Esta pantalla se desactiva automaticamente una vez completada la instalacion.
        </p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= View::e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/instalar">
            <input type="hidden" name="_csrf" value="<?= View::e(Session::csrfToken()) ?>">

            <h3 style="margin:0 0 .5rem;font-size:.95rem">Base de datos</h3>
            <p class="text-muted" style="font-size:.8rem;margin-top:0">Estos datos los creas en cPanel &rarr; Bases de datos MySQL.</p>

            <div class="form-row">
                <div class="form-group">
                    <label>Host</label>
                    <input type="text" name="db_host" value="<?= View::e($valores['dbHost'] ?? '127.0.0.1') ?>" required>
                </div>
                <div class="form-group">
                    <label>Puerto</label>
                    <input type="text" name="db_port" value="<?= View::e($valores['dbPort'] ?? '3306') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Nombre de la base de datos</label>
                <input type="text" name="db_database" value="<?= View::e($valores['dbDatabase'] ?? '') ?>" placeholder="ej. usuario_horarios" required>
            </div>
            <div class="form-group">
                <label>Usuario MySQL</label>
                <input type="text" name="db_username" value="<?= View::e($valores['dbUsername'] ?? '') ?>" placeholder="ej. usuario_horarios" required>
            </div>
            <div class="form-group">
                <label>Contrasena MySQL</label>
                <input type="password" name="db_password" autocomplete="off">
            </div>

            <h3 style="margin:1.25rem 0 .5rem;font-size:.95rem">Sistema</h3>
            <div class="form-group">
                <label>URL del sitio</label>
                <input type="text" name="app_url" value="<?= View::e($valores['appUrl'] ?? '') ?>" placeholder="https://horarios.inalde.edu.co" required>
            </div>

            <h3 style="margin:1.25rem 0 .5rem;font-size:.95rem">Usuario Administrador</h3>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="admin_nombre" value="<?= View::e($valores['adminNombre'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Correo</label>
                <input type="email" name="admin_email" value="<?= View::e($valores['adminEmail'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Contrasena (minimo 8 caracteres)</label>
                <input type="password" name="admin_password" minlength="8" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">Instalar</button>
        </form>
    </div>
</div>
</body>
</html>
