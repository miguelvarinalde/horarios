<?php /** @var int $status */ /** @var string $message */ ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Error <?= (int) $status ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background:#f4f5f7; color:#1f2430; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:2rem 2.5rem; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,.08); text-align:center; }
        h1 { font-size:2.5rem; margin:0 0 .5rem; color:#c0392b; }
        p { color:#555; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?= (int) $status ?></h1>
        <p><?= htmlspecialchars($message ?: 'Ha ocurrido un error.', ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</body>
</html>
