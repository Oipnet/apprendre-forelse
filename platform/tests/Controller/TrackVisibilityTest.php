<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Un parcours « visibility: admin » n'existe que pour les administrateurs. */
final class TrackVisibilityTest extends WebTestCase
{
    use DatabaseTrait;

    private const string ROOT = __DIR__.'/../../..';
    private string $packs;
    private ?string $cheminsInitiaux = null;

    protected function setUp(): void
    {
        $this->packs = sys_get_temp_dir().'/visibilite-packs-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(self::ROOT.'/examples/packs/demo', $this->packs.'/demo');
        $track = $this->packs.'/demo/tracks/decouverte/track.yaml';
        file_put_contents($track, str_replace("environment: symfony-8\n", "environment: symfony-8\nvisibility: admin\n", (string) file_get_contents($track)));
        $this->cheminsInitiaux = $_SERVER['CONTENT_PACKS_PATHS'] ?? null;
        $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->packs;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->packs);
        if (null === $this->cheminsInitiaux) {
            unset($_SERVER['CONTENT_PACKS_PATHS'], $_ENV['CONTENT_PACKS_PATHS']);
        } else {
            $_SERVER['CONTENT_PACKS_PATHS'] = $_ENV['CONTENT_PACKS_PATHS'] = $this->cheminsInitiaux;
        }
        parent::tearDown();
    }

    public function testUnParcoursReserveEstInvisiblePourLesAutres(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('/parcours/decouverte', (string) $client->getResponse()->getContent(), 'L\'accueil ne mentionne pas le parcours réservé.');

        foreach (['/parcours/decouverte', '/parcours/decouverte/01-bonjour', '/api/exercises/decouverte/01-bonjour', '/parcours/decouverte/chapitre/bonjour'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, sprintf('%s n\'existe pas pour un visiteur.', $url));
        }

        $client->loginUser($this->apprenant($client));
        $client->request('GET', '/parcours/decouverte');
        $this->assertResponseStatusCodeSame(404, 'Ni pour un apprenant connecté.');
    }

    public function testUnAdministrateurVoitLeParcoursReserve(): void
    {
        $client = static::createClient();
        $admin = $this->apprenant($client, 'admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($admin);

        $client->request('GET', '/');
        $this->assertStringContainsString('/parcours/decouverte', (string) $client->getResponse()->getContent(), 'L\'accueil liste le parcours pour un administrateur.');

        $client->request('GET', '/parcours/decouverte');
        $this->assertResponseIsSuccessful();
        $client->request('GET', '/api/exercises/decouverte/01-bonjour');
        $this->assertResponseIsSuccessful();
    }

    private function apprenant(KernelBrowser $client, string $email = 'ada@example.test', string $nom = 'Ada'): User
    {
        $this->resetDatabase();

        return $this->createUser($email, $nom);
    }
}
