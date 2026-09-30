<?php

namespace App\Tests;

use App\Theme\FixedTheme;
use App\Theme\Theme;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Construit un thème hors du conteneur, pour les tests unitaires : le dossier donné, ou celui du
 * moteur quand il est vide. Le routeur ne connaît que les routes des images et des fichiers du thème.
 */
trait ThemeTrait
{
    protected static function theme(string $directory = ''): Theme
    {
        $routes = new RouteCollection();
        $routes->add('app_theme_image', new Route('/theme/{theme}/{version}/{role}'));
        $routes->add('app_theme_asset', new Route('/theme/{theme}/{version}/assets/{path}', requirements: ['path' => '.+']));

        return new Theme(
            new FixedTheme($directory),
            new UrlGenerator($routes, new RequestContext()),
            new Packages(new PathPackage('/', new EmptyVersionStrategy())),
        );
    }
}
