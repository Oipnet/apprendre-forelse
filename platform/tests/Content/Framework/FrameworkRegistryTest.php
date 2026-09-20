<?php

namespace App\Tests\Content\Framework;

use App\Content\ContentException;
use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkProfileProvider;
use App\Content\Framework\FrameworkRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Le registre des frameworks : ce que le moteur sait faire tourner, déclaré une fois.
 *
 * Le test qui compte est le dernier : ajouter un framework ne doit demander qu'un
 * FrameworkProfileProvider, pas une retouche du moteur. C'est la moitié serveur du contrat qu'un
 * paquet de plateforme remplira (voir ANALYSE-MARQUE-BLANCHE.md).
 */
final class FrameworkRegistryTest extends TestCase
{
    public function testLesQuatreFrameworksLivresSontLa(): void
    {
        $registry = new FrameworkRegistry();

        $this->assertSame(['symfony', 'laravel', 'docker', 'nuxt'], $registry->ids());
        $this->assertSame(['symfony' => 'Symfony', 'laravel' => 'Laravel', 'docker' => 'Docker', 'nuxt' => 'Nuxt'], $registry->labels());
        $this->assertSame('symfony', $registry->default()->id);
    }

    public function testChaqueProfilDitCommentSesTestsSeLancent(): void
    {
        $registry = new FrameworkRegistry();

        $this->assertTrue($registry->get('symfony')->runsPhpunit());
        $this->assertTrue($registry->get('docker')->runsPhpunit());
        $this->assertFalse($registry->get('nuxt')->runsPhpunit(), 'Les tests d\'un projet Nuxt sont des tests Vitest.');
        // « version: » ne vaut que pour un framework dont un paquet porte la version.
        $this->assertSame('symfony/framework-bundle', $registry->get('symfony')->versionPackage);
        $this->assertNull($registry->get('docker')->versionPackage);
    }

    public function testUnFrameworkInconnuEstSignaleAvecLaListe(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Framework « rails » inconnu (symfony, laravel, docker, nuxt)');
        (new FrameworkRegistry())->get('rails');
    }

    /** Ce que le navigateur reçoit : les clés sont le contrat avec playground/src/app/types.ts. */
    public function testLeProfilServiAuNavigateurNeLivreQueCeQuiLuiSert(): void
    {
        $browser = (new FrameworkRegistry())->get('laravel')->forBrowser();

        $this->assertSame('php artisan', $browser['console']);
        $this->assertSame(['storage/framework/views'], $browser['testCaches']);
        $this->assertArrayNotHasKey('drafting', $browser, 'Les consignes du modèle ne regardent pas le navigateur.');
        $this->assertArrayNotHasKey('codeDirs', $browser);
    }

    /** Ajouter un framework : une classe, et le registre le connaît. Rien d'autre à toucher. */
    public function testUnProfilFourniDuDehorsEstAccepte(): void
    {
        // Dans le désordre : c'est le rang déclaré qui range, pas l'ordre de découverte du conteneur.
        $registry = new FrameworkRegistry([new SlimLike(), new SymfonyLike()]);

        $this->assertSame(['symfony', 'slim'], $registry->ids());
        $this->assertSame('Slim', $registry->get('slim')->label);
    }

    /** Sans profil par défaut, le moteur ne sait plus quoi prendre : il le dit plutôt que de deviner. */
    public function testUnRegistreSansFrameworkParDefautEstRefuse(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Aucun profil « symfony »');
        new FrameworkRegistry([new SlimLike()]);
    }
}

/** Un profil minimal, pour montrer ce qu'un paquet de plateforme aurait à fournir. */
abstract class FakeProfile implements FrameworkProfileProvider
{
    abstract protected function id(): string;

    abstract protected function label(): string;

    abstract protected function order(): int;

    public function profile(): FrameworkProfile
    {
        return new FrameworkProfile(
            id: $this->id(),
            label: $this->label(),
            order: $this->order(),
            console: 'bin/console',
            consoleExample: 'list',
            bootNote: 'Note.',
            unpackLabel: 'Décompression.',
            testRunner: FrameworkProfile::PHPUNIT,
            versionPackage: null,
            projectDirs: ['src'],
            codeDirs: ['src/'],
            cacheDirs: [],
            testCaches: [],
            hidden: ['vendor'],
            namespaceRoots: ['src' => 'App'],
            lessonLanguages: '```php',
            drafting: null,
        );
    }
}

final class SymfonyLike extends FakeProfile
{
    protected function id(): string
    {
        return 'symfony';
    }

    protected function label(): string
    {
        return 'Symfony';
    }

    protected function order(): int
    {
        return 10;
    }
}

final class SlimLike extends FakeProfile
{
    protected function id(): string
    {
        return 'slim';
    }

    protected function label(): string
    {
        return 'Slim';
    }

    protected function order(): int
    {
        return 50;
    }
}
