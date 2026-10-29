<?php

// Les classes des tests sont chargées ici, et non par l'autoload du projet : l'apprenant réécrit composer.json au
// chapitre 4, et ses propres classes ne doivent se charger que par son autoloader (chapitre 3) ou par le sien.
require dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Page.php';
require_once __DIR__.'/GazetteTestCase.php';
