<?php

// Lancez « php bin/bonjour.php Ada » dans la console : $argv reçoit les arguments, comme dans un terminal.
require __DIR__.'/../vendor/autoload.php';

use App\Salutation;

echo (new Salutation())->pour($argv[1] ?? 'tout le monde'), PHP_EOL;
