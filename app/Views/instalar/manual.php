<?php use App\Core\View; ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalacion - copia manual del .env</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-box" style="max-width:560px">
        <h1>Falta un paso manual</h1>
        <p>
            La base de datos ya quedo migrada y el usuario Administrador creado correctamente, pero el
            servidor no tiene permiso para escribir el archivo <code class="inline">.env</code>
            automaticamente. Copia el siguiente contenido y guardalo como <code class="inline">.env</code>
            en la carpeta raiz del proyecto (junto a <code class="inline">composer.json</code>), usando el
            Administrador de archivos de cPanel o FTP.
        </p>
        <textarea readonly style="width:100%;height:260px;font-family:monospace;font-size:.8rem;padding:.75rem;border:1px solid var(--color-border);border-radius:6px"><?= View::e($envContenido) ?></textarea>
        <p class="text-muted" style="margin-top:1rem">
            Despues de guardar el archivo, recarga <a href="/login">la pantalla de inicio de sesion</a>.
        </p>
    </div>
</div>
</body>
</html>
