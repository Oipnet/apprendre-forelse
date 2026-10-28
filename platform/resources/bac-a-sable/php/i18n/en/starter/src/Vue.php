<?php

namespace App;

final class Vue
{
    /** Renders templates/<nom>.php with these variables, and returns the HTML. */
    public static function rendre(string $nom, array $variables = []): string
    {
        extract($variables);
        ob_start();
        require __DIR__.'/../templates/'.$nom.'.php';

        return (string) ob_get_clean();
    }
}
