<?php

namespace App\Tests;

use App\Instance\Branding;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Construit une marque hors du conteneur, pour les tests unitaires : le dossier donné, ou celle du
 * moteur quand il est vide. Le routeur ne connaît que la route des images de marque, la seule utilisée.
 */
trait BrandingTrait
{
    protected static function branding(string $directory = ''): Branding
    {
        $routes = new RouteCollection();
        $routes->add('app_brand_image', new Route('/marque/{role}'));

        return new Branding(
            $directory,
            new UrlGenerator($routes, new RequestContext()),
            new Packages(new PathPackage('/', new EmptyVersionStrategy())),
        );
    }
}
