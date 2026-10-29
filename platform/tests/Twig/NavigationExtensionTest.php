<?php

namespace App\Tests\Twig;

use App\Content\Track;
use App\Tests\PacksTrait;
use App\Twig\NavigationExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** header_tracks() : pour un visiteur, les parcours ouverts d'un côté, ceux en préparation (« bientôt ») de l'autre. */
final class NavigationExtensionTest extends KernelTestCase
{
    use PacksTrait;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/cohortes');
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testUnParcoursEnPreparationSAnnonceAPartDesParcoursOuverts(): void
    {
        $menu = static::getContainer()->get(NavigationExtension::class)->headerTracks();

        $ids = static fn (array $tracks) => array_map(static fn (Track $track) => $track->id, $tracks);
        $this->assertEqualsCanonicalizing(['symfony-bases', 'laravel-bases'], $ids($menu['tracks']));
        $this->assertSame(['atelier-secret'], $ids($menu['upcoming']));
    }
}
