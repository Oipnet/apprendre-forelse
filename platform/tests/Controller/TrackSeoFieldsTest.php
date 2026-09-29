<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Les champs SEO d'un parcours : son niveau (« level ») et la description de ses chapitres. */
final class TrackSeoFieldsTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const string DESCRIPTION = 'Une première route Symfony, puis une page qui salue par son prénom : le trajet d\'une requête, de l\'URL au contrôleur.';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/seo-champs-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/../../../examples/packs/demo', $this->tmp.'/demo');
        $trackYaml = $this->tmp.'/demo/tracks/decouverte/track.yaml';
        $yaml = (string) file_get_contents($trackYaml);
        $yaml = preg_replace('/(  - id: bonjour\n    title: Bonjour Symfony\n)/', '$1    description: "'.self::DESCRIPTION.'"'."\n", $yaml, 1, $count);
        $this->assertSame(1, $count, 'Le chapitre « bonjour » du pack de démo a changé de forme.');
        $filesystem->dumpFile($trackYaml, "level: beginner\n".$yaml);
        $this->usePacks($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    public function testLeNiveauEtLaDescriptionDuChapitreSontRepris(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/parcours/decouverte');
        $course = $this->jsonLd($crawler, 'Course');
        $this->assertSame('Débutant', $course['educationalLevel']);

        $crawler = $client->request('GET', '/parcours/decouverte/chapitre/bonjour/sommaire');
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::DESCRIPTION, $crawler->filter('meta[name="description"]')->attr('content'));
        $this->assertSelectorTextSame('.chapter-outline .lead', self::DESCRIPTION, 'Elle se lit aussi sur la page, pas seulement dans la meta.');

        $crawler = $client->request('GET', '/parcours/decouverte/01-bonjour');
        $this->assertSame('Débutant', $this->jsonLd($crawler, 'LearningResource')['educationalLevel']);
    }

    /** @return array<string, mixed> */
    private function jsonLd(\Symfony\Component\DomCrawler\Crawler $crawler, string $type): array
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            if ($type === $data['@type']) {
                return $data;
            }
        }
        $this->fail(sprintf('Pas de %s.', $type));
    }
}
