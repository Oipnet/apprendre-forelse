<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($titre) ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($titre) ?></h1>
    <p>PHP <?= PHP_VERSION ?>, in your browser.</p>

    <h2>Things to try</h2>
    <ul>
        <?php foreach ($idees as $idee): ?>
            <li><?= htmlspecialchars($idee) ?></li>
        <?php endforeach ?>
    </ul>

    <p><a href="<?= htmlspecialchars($base) ?>/bonjour/Ada">A JSON response</a></p>
</body>
</html>
