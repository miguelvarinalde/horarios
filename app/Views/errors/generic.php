<?php /** @var int $status */ /** @var string $message */ ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Error <?= (int) $status ?></title>
    <link rel="stylesheet" href="<?= \App\Core\View::asset('/assets/css/error.css') ?>">
</head>
<body>
    <div class="box">
        <h1><?= (int) $status ?></h1>
        <p><?= htmlspecialchars($message ?: 'Ha ocurrido un error.', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</body>
</html>
