<?php

// Run "php bin/bonjour.php Ada" in the console: $argv receives the arguments, as in a terminal.
require __DIR__.'/../vendor/autoload.php';

use App\Salutation;

echo (new Salutation())->pour($argv[1] ?? 'everyone'), PHP_EOL;
