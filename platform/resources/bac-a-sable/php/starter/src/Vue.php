<?php

namespace App;

final class Vue
{
    /** Rend templates/<nom>.php avec ces variables, et renvoie le HTML produit. */
    public static function rendre(string $nom, array $variables = []): string
    {
        extract($variables);
        ob_start();
        require __DIR__.'/../templates/'.$nom.'.php';

        return (string) ob_get_clean();
    }
}
