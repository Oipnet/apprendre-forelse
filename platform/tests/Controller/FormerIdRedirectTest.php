<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Un parcours ou un exercice renommé garde ses anciennes adresses (clé « former_ids ») : elles redirigent en 301. */
final class FormerIdRedirectTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private string $tmp;

    protected function setUp(): void
    {
        // Le pack de démo, comme si « decouverte », « 01-bonjour » et la Pratique avaient été renommés.
        $this->tmp = sys_get_temp_dir().'/anciens-ids-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/../../../examples/packs/demo', $this->tmp.'/demo');
        foreach ([
            'tracks/decouverte/track.yaml' => 'former_ids: [premiers-pas]',
            'tracks/decouverte/exercises/01-bonjour/exercise.yaml' => 'former_ids: [01-salut]',
            'practice/exemple-map-request-header/exercise.yaml' => 'former_ids: [lire-un-entete]',
        ] as $file => $line) {
            $filesystem->dumpFile($this->tmp.'/demo/'.$file, $line."\n".file_get_contents($this->tmp.'/demo/'.$file));
        }
        $this->usePacks($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    public function testLesAnciennesAdressesRedirigentVersLesNouvelles(): void
    {
        $client = static::createClient();
        foreach ([
            '/parcours/premiers-pas' => '/parcours/decouverte',
            '/parcours/premiers-pas/chapitre/bonjour/sommaire' => '/parcours/decouverte/chapitre/bonjour/sommaire',
            '/parcours/decouverte/01-salut' => '/parcours/decouverte/01-bonjour',
            '/parcours/premiers-pas/01-salut' => '/parcours/decouverte/01-bonjour',
            '/parcours/premiers-pas/02-bonjour-prenom' => '/parcours/decouverte/02-bonjour-prenom',
            '/pratique/lire-un-entete?utm_source=x' => '/pratique/exemple-map-request-header?utm_source=x',
        ] as $old => $new) {
            $client->request('GET', $old);
            $this->assertResponseStatusCodeSame(301, $old);
            $this->assertResponseRedirects($new, 301, $old);
        }

        $client->followRedirects();
        $client->request('GET', '/parcours/premiers-pas/01-salut');
        $this->assertResponseIsSuccessful('La nouvelle adresse répond.');
    }

    public function testUneAdresseInconnueResteIntrouvable(): void
    {
        $client = static::createClient();
        foreach (['/parcours/inconnu', '/parcours/decouverte/inconnu', '/pratique/inconnu'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testLApiNeRedirigePas(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());
        $client->request('GET', '/api/exercises/premiers-pas/01-salut', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(404);
    }
}
