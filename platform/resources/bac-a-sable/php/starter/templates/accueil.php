<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($titre) ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($titre) ?></h1>
    <p>PHP <?= PHP_VERSION ?>, dans votre navigateur.</p>

    <h2>À essayer</h2>
    <ul>
        <?php foreach ($idees as $idee): ?>
            <li><?= htmlspecialchars($idee) ?></li>
        <?php endforeach ?>
    </ul>

    <p><a href="<?= htmlspecialchars($base) ?>/bonjour/Ada">Une réponse JSON</a></p>
</body>
</html>
