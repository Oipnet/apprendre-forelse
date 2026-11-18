<?php

namespace App\Tests\Controller;

use App\Entity\TrackAccess;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use App\Tests\PaymentTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'encart du parcours sous l'aperçu du bac à sable (App\Scratch\ScratchOfferFactory) : le parcours qui se déclare
 * dans son track.yaml (`scratch: [php]`), au prix que ce visiteur paierait, et seulement s'il peut l'acheter.
 */
final class ScratchOfferTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePaidPack();
        FakePaymentGateway::reset();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** @return array<string, mixed>|null */
    private function offer(string $url): ?array
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true, flags: \JSON_THROW_ON_ERROR);

        return $config['scratch']['offer'];
    }

    public function testLeBacASablePhpProposeLeParcoursAuPrixDuVisiteur(): void
    {
        $this->setPrice('payant', 2900, 1900);

        $offer = $this->offer('/bac-a-sable/php');

        $this->assertNotNull($offer);
        $this->assertSame('payant', $offer['track']);
        $this->assertSame('/parcours/payant', $offer['url']);
        $this->assertSame("Vous aimez essayer du PHP ici\u{a0}?", $offer['title'], 'Insécable avant le point d\'interrogation.');
        $this->assertStringContainsString("«\u{a0}Parcours payant\u{a0}»", $offer['text']);
        $this->assertMatchesRegularExpression('/^19,00\s€ TTC, accès à vie, premier chapitre gratuit avec un compte\.$/u', $offer['price'], 'Le prix fondateur, celui que le visiteur paierait.');
        $this->assertSame('Voir le parcours', $offer['cta']);
    }

    public function testEnAnglaisLeParcoursAnglais(): void
    {
        $this->setPrice('payant', 2900, 1900);

        $offer = $this->offer('/en/playground/php');

        $this->assertNotNull($offer);
        $this->assertSame('/en/tracks/payant', $offer['url']);
        $this->assertSame('Enjoying PHP in here?', $offer['title']);
        $this->assertStringContainsString('“Paid track”', $offer['text']);
        $this->assertStringContainsString('incl. VAT, lifetime access', $offer['price']);
        $this->assertStringContainsString('19.00', $offer['price']);
    }

    public function testRienDansLesBacsASableQueLeParcoursNeDeclarePas(): void
    {
        $this->setPrice('payant', 2900);

        $this->assertNull($this->offer('/bac-a-sable/symfony'));
        $this->assertNull($this->offer('/bac-a-sable/laravel'));
    }

    public function testRienPourUnParcoursGratuit(): void
    {
        $this->assertNull($this->offer('/bac-a-sable/php'), 'Sans tarif, rien à vendre.');
    }

    public function testRienPourQuiADejaLeParcours(): void
    {
        $this->setPrice('payant', 2900);
        $ada = $this->createUser();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(TrackAccess::gift($ada, 'payant', new \DateTimeImmutable('-1 day')));
        $entityManager->flush();
        $this->client->loginUser($ada);

        $this->assertNull($this->offer('/bac-a-sable/php'));
    }
}
