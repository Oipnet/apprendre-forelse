<?php

namespace App\Tests\Controller;

use App\Entity\TrackSeo;
use App\Entity\User;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Title et description d'une page de parcours : générés, ou saisis dans l'admin. */
final class TrackSeoTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const string SYMFONY = 'Vous maîtrisez PHP et la POO ? Apprenez Symfony en construisant, chapitre après chapitre, le site de la Taverne du Dragon Ivre.';

    private string $packs;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        // Le pack de démo, déguisé en parcours « Symfony pour les devs PHP » (le vrai vit dans un dépôt privé).
        $this->packs = sys_get_temp_dir().'/seo-parcours-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(__DIR__.'/../../../examples/packs/demo', $this->packs.'/demo');
        $file = $this->packs.'/demo/tracks/decouverte/track.yaml';
        $yaml = (string) file_get_contents($file);
        $yaml = (string) preg_replace('/^title: .*$/m', 'title: Symfony pour les devs PHP', $yaml, 1);
        $yaml = (string) preg_replace('/^description: .*$/m', 'description: '.self::SYMFONY, $yaml, 1);
        file_put_contents($file, $yaml);
        $this->usePacks($this->packs);

        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        (new Filesystem())->remove($this->packs);
        parent::tearDown();
    }

    public function testLaPageDuParcoursRendSonTitleEtSaDescription(): void
    {
        $crawler = $this->client->request('GET', '/parcours/decouverte');

        $title = 'Formation Symfony en ligne pour les devs PHP | Forelse';
        $description = 'Vous maîtrisez PHP et la POO ? Apprenez Symfony en construisant, chapitre après chapitre, le site… 1 chapitre, 2 exercices, premier chapitre gratuit.';
        $this->assertSame($title, $crawler->filter('title')->text());
        $this->assertSame($title, $crawler->filter('meta[property="og:title"]')->attr('content'));
        $this->assertSame($description, $crawler->filter('meta[name="description"]')->attr('content'));
        $this->assertSame($description, $crawler->filter('meta[property="og:description"]')->attr('content'));
    }

    public function testLeReferencementSaisiDansLAdminPasseAvant(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new TrackSeo('decouverte'))->setSeoTitle('Apprendre Symfony en codant')->setSeoDescription('Une description écrite à la main pour la page du parcours Symfony.'));
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/parcours/decouverte');
        $this->assertSame('Apprendre Symfony en codant | Forelse', $crawler->filter('title')->text());
        $this->assertSame('Une description écrite à la main pour la page du parcours Symfony.', $crawler->filter('meta[property="og:description"]')->attr('content'));

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/referencement');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Formation Symfony en ligne pour les devs PHP', 'La liste montre le title généré.');
        $this->client->request('GET', '/admin/referencement/new');
        $this->assertResponseIsSuccessful();
    }
}
