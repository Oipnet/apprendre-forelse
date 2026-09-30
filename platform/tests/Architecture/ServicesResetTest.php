<?php

namespace App\Tests\Architecture;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Theme\Theme;
use App\Twig\ConceptExtension;
use App\Twig\FontPreloadExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Mode worker de FrankenPHP : un même service sert plusieurs requêtes. Ce qu'il garde en mémoire doit être oublié entre
 * deux d'entre elles (ResetInterface), sinon un pack redéposé, un environnement installé ou une marque modifiée ne se
 * verraient plus avant un redémarrage. Vérifie que Symfony vide bien ces services, comme il le fait entre deux requêtes.
 */
final class ServicesResetTest extends KernelTestCase
{
    public function testLesServicesQuiGardentUnEtatSontVidesEntreDeuxRequetes(): void
    {
        $container = static::getContainer();
        $services = [
            ContentRepository::class => ['content', static fn (ContentRepository $s) => $s->tracks()],
            EnvironmentRegistry::class => ['directoriesById', static fn (EnvironmentRegistry $s) => $s->all()],
            Theme::class => ['config', static fn (Theme $s) => $s->name()],
            ConceptExtension::class => ['urls', static fn (ConceptExtension $s) => $s->url('Boucle Twig')],
            FontPreloadExtension::class => ['urls', static fn (FontPreloadExtension $s) => $s->urls()],
        ];

        foreach ($services as $id => [$property, $warm]) {
            $service = $container->get($id);
            $warm($service);
            $this->assertNotNull((new \ReflectionProperty($service, $property))->getValue($service), $id.' : rien en mémoire, le test ne prouve rien.');
        }

        $container->get('services_resetter')->reset();

        foreach ($services as $id => [$property]) {
            $service = $container->get($id);
            $this->assertNull((new \ReflectionProperty($service, $property))->getValue($service), $id.' garde son état d\'une requête à l\'autre.');
        }
    }
}
