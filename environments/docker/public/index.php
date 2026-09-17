<?php

// Le point d'entrée de l'application : c'est lui que le serveur web du conteneur exécute.
echo '<h1>Ça marche !</h1>';
echo '<p>PHP '.PHP_VERSION.', servi par '.($_SERVER['SERVER_SOFTWARE'] ?? 'un serveur inconnu').'.</p>';
