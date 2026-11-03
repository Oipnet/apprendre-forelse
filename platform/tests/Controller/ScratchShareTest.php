<?php

namespace App\Tests\Controller;

use App\Entity\ScratchShare;
use App\Scratch\ScratchLink;
use App\Scratch\ScratchShortLinks;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les liens courts du bac à sable (case « Lien court » de la boîte de partage) : le code gardé sur le serveur sous un
 * identifiant de 8 caractères, valable sans limite par défaut, supprimable par le navigateur qui l'a créé.
 */
final class ScratchShareTest extends WebTestCase
{
    use DatabaseTrait;

    private KernelBrowser $client;
    private string $code;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
        // Les compteurs du limiteur survivent d'un test à l'autre (table cache_items) : chaque test repart de zéro.
        static::getContainer()->get('limiter.scratch_share')->create('127.0.0.1')->reset();
        $this->code = (new ScratchLink())->encode(['src/Controller/AccueilController.php' => "<?php\n// Bonjour"]);
    }

    public function testLaPageDuProjetDonneLAdresseDeLApiDesLiensCourts(): void
    {
        $crawler = $this->client->request('GET', '/bac-a-sable/symfony');

        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['url' => '/api/bac-a-sable/symfony/liens'], $config['scratch']['shortLinks']);
    }

    public function testUnLienCourtRameneAuProjetAvecSonCodeSansSeFaireIndexer(): void
    {
        $created = $this->create('symfony', ['code' => $this->code]);

        $this->assertMatchesRegularExpression('~^http://localhost/bac-a-sable/s/[0-9a-zA-Z]{8}$~', $created['url']);
        $this->assertNull($created['expiresAt'], 'Sans limite par défaut.');
        $this->assertSame($created['url'], $created['share']['lien'], 'Le lien à copier reste nu : le plus court possible.');
        $this->assertStringContainsString($created['url'].'?utm_source=linkedin', urldecode($created['share']['linkedin']), 'Les réseaux gardent leur utm_source.');

        $this->client->request('GET', (string) parse_url($created['url'], \PHP_URL_PATH));

        $this->assertResponseRedirects('/bac-a-sable/symfony?utm_source=lien&utm_medium=partage&utm_campaign=bac-a-sable#'.$this->code);
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function testLesParametresDuLienCourtSuiventLaRedirection(): void
    {
        $created = $this->create('symfony', ['code' => $this->code]);

        $this->client->request('GET', parse_url($created['url'], \PHP_URL_PATH).'?utm_source=grafikart&utm_medium=social');

        $this->assertResponseRedirects('/bac-a-sable/symfony?utm_source=grafikart&utm_medium=social#'.$this->code);
    }

    public function testUnLienCreeEnAnglaisRameneALaPageAnglaise(): void
    {
        $this->client->request('POST', '/en/api/bac-a-sable/laravel/liens', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['code' => $this->code, 'duree' => '1a'], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression('~^http://localhost/en/playground/s/[0-9a-zA-Z]{8}$~', $created['url']);
        $this->assertNotNull($created['expiresAt']);
        $this->client->request('GET', (string) parse_url($created['url'], \PHP_URL_PATH));
        $this->assertResponseRedirects('/en/playground/laravel?utm_source=lien&utm_medium=partage&utm_campaign=bac-a-sable#'.$this->code);
    }

    public function testSeulUnCodeQueLaPageSauraitLireEstGarde(): void
    {
        foreach ([['code' => 'code=pas-du-deflate'], ['code' => 'nouveau'], []] as $body) {
            $this->client->request('POST', '/api/bac-a-sable/php/liens', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
            $this->assertResponseStatusCodeSame(422);
        }
        $this->client->request('POST', '/api/bac-a-sable/php/liens', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['code' => $this->code, 'duree' => '10ans'], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/api/bac-a-sable/php/liens', server: ['CONTENT_TYPE' => 'application/json'], content: 'pas du json');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $this->entityManager()->getRepository(ScratchShare::class)->count([]));
    }

    public function testUnAutreSiteNePeutPasCreerDeLienAuNomDuVisiteur(): void
    {
        $this->client->request('POST', '/api/bac-a-sable/php/liens', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://ailleurs.example'], content: json_encode(['code' => $this->code], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSeuleLaCleDuCreateurSupprimeLeLien(): void
    {
        $created = $this->create('php', ['code' => $this->code]);
        $path = (string) parse_url($created['url'], \PHP_URL_PATH);

        $this->client->request('DELETE', $created['deleteUrl'], server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['cle' => 'pas-la-bonne'], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', $path);
        $this->assertResponseRedirects();

        $this->client->request('DELETE', $created['deleteUrl'], server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['cle' => $created['deleteKey']], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('GET', $path);
        $this->assertResponseStatusCodeSame(410);
        $this->assertSelectorTextContains('h1', 'Ce lien de bac à sable n\'existe plus');
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function testUnLienExpireNeSOuvrePlusEtLaPurgeLEfface(): void
    {
        $expire = $this->create('symfony', ['code' => $this->code, 'duree' => '1m']);
        $jamais = $this->create('symfony', ['code' => $this->code]);
        $this->assertNotSame($expire['id'], $jamais['id']);
        $this->entityManager()->getConnection()->executeStatement("UPDATE scratch_share SET expires_at = NOW() - INTERVAL '1 day' WHERE id = ?", [$expire['id']]);
        $this->entityManager()->clear();

        $this->client->request('GET', (string) parse_url($expire['url'], \PHP_URL_PATH));
        $this->assertResponseStatusCodeSame(410);

        $this->assertSame(1, static::getContainer()->get(ScratchShortLinks::class)->purge());
        $this->entityManager()->clear();
        $this->assertNull($this->entityManager()->find(ScratchShare::class, $expire['id']));
        $this->assertNotNull($this->entityManager()->find(ScratchShare::class, $jamais['id']), 'Un lien sans limite reste.');
    }

    public function testUnIdentifiantInconnuOuMalFormeNOuvreRien(): void
    {
        $this->client->request('GET', '/bac-a-sable/s/Zz99Zz99');
        $this->assertResponseStatusCodeSame(410);
        $this->client->request('GET', '/bac-a-sable/s/trop-long-pour-etre-un-lien');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function create(string $framework, array $body): array
    {
        $this->client->request('POST', '/api/bac-a-sable/'.$framework.'/liens', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode($body, \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
