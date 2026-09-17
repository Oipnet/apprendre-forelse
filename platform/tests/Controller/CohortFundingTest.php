<?php

namespace App\Tests\Controller;

use App\Cohort\CohortAccessSync;
use App\Entity\AccessSource;
use App\Entity\Cohort;
use App\Entity\FundingMode;
use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\TrackAccess;
use App\Entity\User;
use App\Service\ProgressService;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use App\Tests\PaymentTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Financement des cohortes et accès qu'elles ouvrent. Packs de test « payant » (chapitres « libre », puis « complet » :
 * e2, e3) et « cohortes » (Bases de Symfony, Bases de Laravel, Atelier en préparation).
 */
final class CohortFundingTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../Fixtures/packs/payant', __DIR__.'/../Fixtures/packs/cohortes');
        FakePaymentGateway::reset();
        $this->client = static::createClient();
        $this->resetDatabase();
        $this->setPrice('payant', 7900, 4900);
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $tracks */
    private function cohort(string $code, array $tracks, FundingMode $mode = FundingMode::Institution, ?\DateTimeImmutable $startsAt = null, ?\DateTimeImmutable $endsAt = null): Cohort
    {
        $cohort = $this->createCohort($code)
            ->setAvailableTrackIds($tracks)
            ->setFundingMode($mode)
            ->setAccessStartsAt($startsAt ?? new \DateTimeImmutable('-1 month'))
            ->setAccessEndsAt($endsAt ?? new \DateTimeImmutable('+11 months'));
        $this->entityManager()->flush();

        return $cohort;
    }

    /** @return array<string, TrackAccess> accès « source:parcours » de l'apprenant, relus en base */
    private function accessesOf(User $user): array
    {
        $this->entityManager()->clear();
        $accesses = [];
        foreach ($this->entityManager()->getRepository(TrackAccess::class)->findBy(['user' => $user->getId()]) as $access) {
            $accesses[$access->getSource()->value.':'.$access->getTrackId()] = $access;
        }

        return $accesses;
    }

    private function register(string $code, string $email = 'ada@example.test'): User
    {
        $this->client->request('GET', '/inscription?code='.$code);
        $this->client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Ada',
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects();

        return $this->entityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function testEntrerDansUneCohorteFinanceeParLEtablissementOuvreSesParcours(): void
    {
        $cohort = $this->cohort('iut-annecy', ['payant', 'symfony-bases']);
        $ada = $this->register('iut-annecy');

        $accesses = $this->accessesOf($ada);
        $this->assertSame(['cohort:payant', 'cohort:symfony-bases'], array_keys($accesses) === ['cohort:payant', 'cohort:symfony-bases'] ? array_keys($accesses) : array_reverse(array_keys($accesses)));
        $this->assertSame($cohort->getAccessEndsAt()->format('Y-m-d H:i:s'), $accesses['cohort:payant']->getEndsAt()?->format('Y-m-d H:i:s'), 'Aux dates de la cohorte.');

        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseIsSuccessful('Le chapitre payant est ouvert par la cohorte.');
        $this->client->request('GET', '/parcours/payant');
        $this->assertSelectorTextContains('.price-box', 'accès à tout le parcours');
    }

    public function testRetirerUnParcoursRevoqueLesAccesDeCohorteEtGardeUnAchat(): void
    {
        $cohort = $this->cohort('iut-annecy', ['symfony-bases']);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        $bob = $this->createUser('bob@example.test', 'Bob', 'iut-annecy');
        // Ada avait acheté le parcours payant elle-même.
        $purchase = new Purchase($ada, 'payant', 4900, PriceKind::Founder, 'renonciation', new \DateTimeImmutable());
        $purchase->attachCheckoutSession('cs_ada');
        $purchase->markPaid(new \DateTimeImmutable(), 4900, 'pi_ada');
        $this->entityManager()->persist($purchase);
        $this->entityManager()->persist(TrackAccess::purchased($purchase, new \DateTimeImmutable()));
        static::getContainer()->get(CohortAccessSync::class)->sync($cohort);

        $chef = $this->createUser('prof@example.test', 'Prof')->setRoles([User::ROLE_CHEF_COHORTE]);
        $cohort = $this->entityManager()->getRepository(Cohort::class)->find($cohort->getId());
        $cohort->addChef($chef);
        $this->entityManager()->flush();
        $this->client->loginUser($chef);

        // Le chef ajoute le parcours payant : les apprenants déjà là reçoivent l'accès.
        $crawler = $this->client->request('GET', '/cohorte/'.$cohort->getId());
        $token = $crawler->selectButton('Enregistrer les parcours')->form()->get('cohort_tracks[_token]')->getValue();
        $this->client->request('POST', '/cohorte/'.$cohort->getId().'/parcours', ['cohort_tracks' => ['trackIds' => ['payant', 'symfony-bases'], '_token' => $token]], server: self::ORIGIN);
        $this->assertResponseRedirects();
        $this->assertArrayHasKey('cohort:payant', $this->accessesOf($bob));
        $this->assertTrue($this->accessesOf($bob)['cohort:payant']->isActive(new \DateTimeImmutable()));

        // Puis le retire : les accès « cohorte » sont révoqués, l'achat d'Ada reste.
        $this->client->request('POST', '/cohorte/'.$cohort->getId().'/parcours', ['cohort_tracks' => ['trackIds' => ['symfony-bases'], '_token' => $token]], server: self::ORIGIN);
        $now = new \DateTimeImmutable();
        $bobs = $this->accessesOf($bob);
        $this->assertFalse($bobs['cohort:payant']->isActive($now), 'Accès cohorte révoqué.');
        $this->assertTrue($bobs['cohort:symfony-bases']->isActive($now), 'Les autres parcours de la cohorte restent ouverts.');
        $adas = $this->accessesOf($ada);
        $this->assertFalse($adas['cohort:payant']->isActive($now));
        $this->assertTrue($adas['purchase:payant']->isActive($now), 'L\'achat n\'est pas touché.');

        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseIsSuccessful('Acheté hors de la sélection de sa cohorte : toujours visible et ouvert.');
        $this->client->loginUser($bob);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(404, 'Retiré de sa cohorte et jamais commencé : le parcours sort de son catalogue.');
    }

    public function testApresLExpirationDeLaCohorteUnAchatRetrouveLaProgression(): void
    {
        $cohort = $this->cohort('promo-2025', ['payant'], startsAt: new \DateTimeImmutable('-13 months'), endsAt: new \DateTimeImmutable('-1 day'));
        $ada = $this->createUser('ada@example.test', 'Ada', 'promo-2025');
        static::getContainer()->get(CohortAccessSync::class)->sync($cohort);
        // Travail fait du temps de la cohorte.
        $e2 = static::getContainer()->get(\App\Content\ContentRepository::class)->findExercise('payant', 'e2');
        static::getContainer()->get(ProgressService::class)->saveDraft($ada, $e2, ['src/Controller/BonjourController.php' => '<?php // mon travail'], 1);

        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403, 'Accès de cohorte expiré.');
        $this->assertSelectorTextContains('main', 'Votre progression est conservée');
        $this->assertSelectorExists('a[href="/parcours/payant/acheter"]');
        $this->json($this->client, 'GET', '/api/progress/payant/e2');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/parcours/payant');
        $this->assertSelectorExists('li[data-state="in_progress"]', 'Le parcours reste au catalogue avec sa progression.');

        $crawler = $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertSelectorTextContains('.price', '49,00', 'Prix courant (la cohorte finançait par l\'établissement).');
        $form = $crawler->selectButton('Payer')->form();
        $form['purchase_confirmation[termsOfSale]']->tick();
        $form['purchase_confirmation[withdrawalWaiver]']->tick();
        $this->client->submit($form, serverParameters: self::ORIGIN);
        $this->entityManager()->clear();
        $purchase = $this->entityManager()->getRepository(Purchase::class)->findOneBy([]);
        $this->assertNull($purchase->getCohort());
        $this->sendPaidWebhook($this->client, (string) $purchase->getStripeSessionId(), 4900);
        $this->assertResponseStatusCodeSame(204);

        $this->client->loginUser($ada);
        $progress = $this->json($this->client, 'GET', '/api/progress/payant/e2');
        $this->assertResponseIsSuccessful();
        $this->assertSame('<?php // mon travail', $progress['files']['src/Controller/BonjourController.php'], 'La progression continue là où elle en était.');
    }

    public function testUneCohorteFinanceeParSesApprenantsNOuvreRienEtFixeSonTarif(): void
    {
        $cohort = $this->cohort('bootcamp', ['payant'], FundingMode::Learners);
        $cohort->setLearnerPrice(2900);
        $this->entityManager()->flush();

        $ada = $this->register('bootcamp');
        $this->assertSame([], $this->accessesOf($ada), 'Pas d\'accès automatique.');

        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertSelectorTextContains('.price', '29,00');
        $this->assertSelectorTextContains('main', 'Tarif de votre cohorte');
        $form = $crawler->selectButton('Payer')->form();
        $form['purchase_confirmation[termsOfSale]']->tick();
        $form['purchase_confirmation[withdrawalWaiver]']->tick();
        $this->client->submit($form, serverParameters: self::ORIGIN);

        $this->entityManager()->clear();
        $purchase = $this->entityManager()->getRepository(Purchase::class)->findOneBy([]);
        $this->assertSame(2900, $purchase->getPrice());
        $this->assertSame(PriceKind::Cohort, $purchase->getPriceKind());
        $this->assertSame($cohort->getId(), $purchase->getCohort()?->getId(), 'L\'achat garde la référence à la cohorte.');
    }

    public function testFinancementVuParLeChefEtParLAdministrateur(): void
    {
        $cohort = $this->cohort('iut-annecy', ['payant', 'symfony-bases']);
        $chef = $this->createUser('prof@example.test', 'Prof')->setRoles([User::ROLE_CHEF_COHORTE]);
        $cohort = $this->entityManager()->getRepository(Cohort::class)->find($cohort->getId());
        $cohort->addChef($chef);
        $this->entityManager()->flush();

        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohort->getId());
        $this->assertSelectorTextContains('#financement', 'Établissement');
        $this->assertSelectorTextContains('#financement', 'pas encore établi');
        $this->assertSelectorNotExists('#financement a[href*="acheter"]', 'Aucune action de paiement.');
        $form = $crawler->filter('#financement form')->form();
        $form['cohort_headcount[expectedHeadcount]'] = '12';
        $this->client->submit($form, serverParameters: self::ORIGIN);
        $this->client->followRedirect();
        // 12 apprenants × 2 parcours × 30 € × 70 % (palier à partir de 10).
        $this->assertSelectorTextContains('#financement', '504,00');

        $admin = $this->createUser('admin@example.test', 'Admin')->setRoles([User::ROLE_ADMIN]);
        $this->entityManager()->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/cohortes/'.$cohort->getId());
        $this->assertSelectorTextContains('#main', '504,00');
        $this->client->request('POST', '/admin/cohortes/'.$cohort->getId().'/appliquer-estimation', server: self::ORIGIN);
        $this->entityManager()->clear();
        $this->assertSame(50400, $this->entityManager()->getRepository(Cohort::class)->find($cohort->getId())->getQuoteAmount());

        // L'effectif change ensuite : le devis est à revoir.
        $this->client->loginUser($chef);
        $crawler = $this->client->request('GET', '/cohorte/'.$cohort->getId());
        $form = $crawler->filter('#financement form')->form();
        $form['cohort_headcount[expectedHeadcount]'] = '20';
        $this->client->submit($form, serverParameters: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('#financement', 'devis à revoir');
    }

    public function testChangerLaCohorteDUnApprenantDansLAdmin(): void
    {
        $this->cohort('iut-annecy', ['payant']);
        $lyon = $this->cohort('iut-lyon', ['symfony-bases']);
        $ada = $this->createUser('ada@example.test', 'Ada', 'iut-annecy');
        static::getContainer()->get(CohortAccessSync::class)->join($ada, $ada->getCohort());
        $admin = $this->createUser('admin@example.test', 'Admin')->setRoles([User::ROLE_ADMIN]);
        $this->entityManager()->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/apprenants/'.$ada->getId().'/edit');
        $this->assertResponseIsSuccessful();
        $this->client->submitForm('Sauvegarder les modifications', ['User[cohort]' => (string) $lyon->getId()], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects();

        $now = new \DateTimeImmutable();
        $accesses = $this->accessesOf($ada);
        $this->assertFalse($accesses['cohort:payant']->isActive($now), 'Les accès de l\'ancienne cohorte sont fermés.');
        $this->assertTrue($accesses['cohort:symfony-bases']->isActive($now), 'Ceux de la nouvelle sont ouverts.');
    }

    public function testInitialisationDesAccesExistants(): void
    {
        $bob = $this->createUser('bob@example.test', 'Bob');
        $sansSelection = $this->createCohort('beta-2026');
        $ada = $this->createUser('ada@example.test', 'Ada', 'beta-2026');

        $tester = new CommandTester((new Application(static::$kernel))->find('app:acces:initialiser'));
        $this->assertSame(0, $tester->execute([]));
        $tester->execute([]);

        $this->entityManager()->clear();
        $cohort = $this->entityManager()->getRepository(Cohort::class)->find($sansSelection->getId());
        $this->assertSame(['payant', 'symfony-bases', 'laravel-bases'], $cohort->getAvailableTrackIds(), 'Tous les parcours publics, désormais choisis ; pas celui en préparation.');
        $this->assertSame(['cohort:laravel-bases', 'cohort:payant', 'cohort:symfony-bases'], self::sortedKeys($this->accessesOf($ada)));
        $this->assertSame(['gift:laravel-bases', 'gift:payant', 'gift:symfony-bases'], self::sortedKeys($this->accessesOf($bob)), 'Relancée, la commande ne crée rien en double.');
        $this->assertNull($this->accessesOf($bob)['gift:payant']->getEndsAt(), 'À vie.');
        $this->assertSame(AccessSource::Gift, $this->accessesOf($bob)['gift:payant']->getSource());
    }

    /** @param array<string, mixed> $values */
    private static function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}
