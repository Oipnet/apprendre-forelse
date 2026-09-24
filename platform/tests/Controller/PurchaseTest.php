<?php

namespace App\Tests\Controller;

use App\Controller\LegalController;
use App\Entity\AccessSource;
use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Entity\StripeEvent;
use App\Entity\TrackAccess;
use App\Entity\TrackPricing;
use App\Entity\User;
use App\Payment\CheckoutSession;
use App\Payment\WithdrawalWaiver;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use App\Tests\PaymentTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Achat individuel d'un parcours. Pack de test « payant » : chapitre « libre » (e1), puis « complet » (e2, e3). */
final class PurchaseTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use PaymentTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

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

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<TrackAccess> */
    private function accessesOf(User $user): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TrackAccess::class)->findBy(['user' => $user->getId()]);
    }

    private function onlyPurchase(): Purchase
    {
        $this->entityManager()->clear();
        $purchases = $this->entityManager()->getRepository(Purchase::class)->findAll();
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }

    /** Ouvre la page de confirmation, accepte les CGV, coche la renonciation et valide : redirigé vers Stripe. */
    private function checkout(bool $waiver = true, bool $terms = true): void
    {
        $crawler = $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Payer')->form();
        if ($terms) {
            $form['purchase_confirmation[termsOfSale]']->tick();
        }
        if ($waiver) {
            $form['purchase_confirmation[withdrawalWaiver]']->tick();
        }
        $this->client->submit($form, serverParameters: self::ORIGIN);
    }

    public function testUnChapitrePayantEstRefuseSansAccesSurLaPageEtLesApi(): void
    {
        $this->setPrice('payant', 7900, 4900);
        $this->client->loginUser($this->createUser());

        $this->client->request('GET', '/parcours/payant/e1');
        $this->assertResponseIsSuccessful('Le premier chapitre reste libre.');

        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('.price-box', '49,00');
        $this->assertSelectorTextContains('.price-box s', '79,00', 'Prix normal barré.');
        $this->assertSelectorExists('.price-box a[href="/parcours/payant/acheter"]');

        foreach ([['GET', '/api/exercises/payant/e2'], ['GET', '/api/progress/payant/e2'], ['PUT', '/api/progress/payant/e2'], ['POST', '/api/progress/payant/e2/complete']] as [$method, $url]) {
            $this->json($this->client, $method, $url, ['files' => [], 'hintsUsed' => 0]);
            $this->assertResponseStatusCodeSame(403, sprintf('%s %s est refusé.', $method, $url));
        }

        $this->client->request('GET', '/parcours/payant');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.chapter.locked h2', 'Parcours complet');
    }

    public function testParcoursDAchatCompletAvecWebhookRecuDeuxFois(): void
    {
        $this->setPrice('payant', 7900, 4900, quota: 100);
        $ada = $this->createUser();
        $this->client->loginUser($ada);

        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('.lp-track-offer', 'Premier chapitre gratuit');
        $this->assertSelectorTextContains('.lp-track-offer', 'encore 100 places');

        $this->checkout();
        $purchase = $this->onlyPurchase();
        $this->assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_'.$purchase->getId(), 303);
        $this->assertSame(PurchaseStatus::Pending, $purchase->getStatus());
        $this->assertSame(4900, $purchase->getPrice());
        $this->assertSame(PriceKind::Founder, $purchase->getPriceKind());
        $this->assertSame(WithdrawalWaiver::TEXT, $purchase->getWithdrawalWaiverText(), 'Le texte coché est conservé avec l\'achat.');
        $this->assertSame(LegalController::TERMS_VERSION, $purchase->getTermsVersion(), 'La version des CGV acceptée aussi.');
        $this->assertNotNull($purchase->getTermsAcceptedAt());
        $this->assertSame('http://localhost/achat/'.$purchase->getId().'/merci', FakePaymentGateway::$sessions[0]['successUrl']);

        // Retour de Stripe avant le webhook : rien n'est ouvert, la page patiente.
        $this->client->request('GET', '/achat/'.$purchase->getId().'/merci');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Paiement en cours de confirmation');
        $this->assertSelectorExists('meta[http-equiv="refresh"]');
        $this->assertCount(0, $this->accessesOf($ada), 'La page de retour n\'ouvre jamais l\'accès.');

        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 4900);
        $this->assertResponseStatusCodeSame(204);
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'to', 'ada@example.test');
        $this->assertEmailHtmlBodyContains($email, 'https://pay.stripe.test/receipts/pi_cs_test_'.$purchase->getId());
        $this->assertEmailHtmlBodyContains($email, 'http://localhost/cgv');

        // Stripe réessaie le même événement, puis un autre événement arrive pour la même session.
        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 4900);
        $this->assertResponseStatusCodeSame(204);
        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 4900, eventId: 'evt_2');
        $this->assertResponseStatusCodeSame(204);

        $accesses = $this->accessesOf($ada);
        $this->assertCount(1, $accesses, 'Un seul accès, même si le webhook est reçu plusieurs fois.');
        $this->assertSame(AccessSource::Purchase, $accesses[0]->getSource());
        $this->assertNull($accesses[0]->getEndsAt(), 'Accès à vie.');
        $purchase = $this->onlyPurchase();
        $this->assertSame(PurchaseStatus::Paid, $purchase->getStatus());
        $this->assertSame(4900, $purchase->getAmountPaid());
        $events = $this->entityManager()->getRepository(StripeEvent::class)->findBy([], ['id' => 'ASC']);
        $this->assertCount(2, $events, 'Chaque événement est journalisé une fois.');
        $this->assertSame(2, $events[0]->getDeliveries());

        $this->client->loginUser($ada);
        $this->client->request('GET', '/achat/'.$purchase->getId().'/merci');
        $this->assertSelectorTextContains('h1', 'Merci');
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseIsSuccessful('Le chapitre payant est ouvert.');
        $this->client->request('GET', '/compte');
        $this->assertSelectorTextContains('main', 'Achat');
        $this->assertSelectorTextContains('main', '49,00');
        $this->assertSelectorExists('a[href="https://pay.stripe.test/receipts/pi_cs_test_'.$purchase->getId().'"]');
        $this->assertSame('in_cs_test_'.$purchase->getId(), $purchase->getStripeInvoiceId(), 'La facture Stripe est notée au paiement.');
        $this->client->click($this->client->getCrawler()->selectLink('Facture (PDF)')->link());
        $this->assertResponseRedirects('https://pay.stripe.test/invoice/in_cs_test_'.$purchase->getId().'/pdf');

        // Déjà acheté : pas de second achat.
        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertResponseRedirects('/parcours/payant');
        // Une fois acheté : plus de prix ni de places restantes, seulement « Continuer ».
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('.lp-track-offer', 'Continuer');
        $this->assertSelectorNotExists('.lp-track-offer .lp-price');
        $this->assertStringNotContainsString('places', $this->client->getCrawler()->filter('.lp-track-offer')->text());
        $this->assertStringNotContainsString('Premier chapitre gratuit', $this->client->getCrawler()->filter('.lp-track-offer')->text());
        $this->client->request('GET', '/parcours/payant');
        $this->assertSelectorNotExists('.chapter h2 .tag.free', 'Plus de mention « gratuit » pour qui a tout le parcours.');

        // Un visiteur voit toujours le prix, et le quota compte les achats au prix fondateur.
        $this->client->restart();
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('.lp-track-offer', 'encore 99 places');
    }

    public function testDeuxDemandesDePaiementRenvoientALaMemeSessionStripe(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        // Deux onglets, ou un retour arrière depuis la page de Stripe.
        $this->checkout();
        $url = $this->client->getResponse()->headers->get('Location');
        $this->checkout();

        $this->assertResponseRedirects($url, 303, 'La même session : Stripe n\'y encaisse qu\'un paiement.');
        $purchase = $this->onlyPurchase();
        $this->assertSame(PurchaseStatus::Pending, $purchase->getStatus());
        $this->assertCount(1, FakePaymentGateway::$sessions);
    }

    public function testUnPaiementFaitDansLAutreOngletMeneALaPageDeRemerciement(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $this->checkout();
        $purchase = $this->onlyPurchase();
        // Payé chez Stripe, webhook pas encore arrivé.
        FakePaymentGateway::$statuses['cs_test_'.$purchase->getId()] = CheckoutSession::COMPLETE;
        $this->checkout();

        $this->assertResponseRedirects('http://localhost/achat/'.$purchase->getId().'/merci', 303);
        $this->assertSame(PurchaseStatus::Pending, $this->onlyPurchase()->getStatus(), 'Le webhook le passera « payé ».');
        $this->assertCount(1, FakePaymentGateway::$sessions);
    }

    public function testUneSessionExpireeLaissePlaceAUnNouvelAchat(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $this->checkout();
        $first = $this->onlyPurchase();
        FakePaymentGateway::$statuses['cs_test_'.$first->getId()] = CheckoutSession::EXPIRED;
        $this->checkout();

        [$old, $new] = $this->purchasesInOrder();
        $this->assertSame(PurchaseStatus::Abandoned, $old->getStatus());
        $this->assertSame(PurchaseStatus::Pending, $new->getStatus());
        $this->assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_'.$new->getId(), 303);
        $this->assertSame([], FakePaymentGateway::$expired, 'Rien à fermer : Stripe l\'a déjà fait.');
    }

    public function testUnPrixChangeFermeLaSessionOuverteAvantDEnOuvrirUneAutre(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $this->checkout();
        $first = $this->onlyPurchase();
        $this->entityManager()->getRepository(TrackPricing::class)->findOneBy(['trackId' => 'payant'])?->setNormalPrice(9900);
        $this->entityManager()->flush();
        $this->checkout();

        [$old, $new] = $this->purchasesInOrder();
        $this->assertSame(['cs_test_'.$first->getId()], FakePaymentGateway::$expired, 'L\'ancien prix ne peut plus être payé.');
        $this->assertSame(PurchaseStatus::Abandoned, $old->getStatus());
        $this->assertSame(9900, $new->getPrice());
        $this->assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_'.$new->getId(), 303);

        // Un achat abandonné n'apparaît pas dans le compte.
        $this->client->request('GET', '/compte');
        $this->assertStringNotContainsString('79,00', $this->client->getCrawler()->filter('main')->text());
    }

    public function testUnPaiementEnCoursReserveLaDernierePlaceFondateur(): void
    {
        $this->setPrice('payant', 7900, 4900, quota: 1);
        $ada = $this->createUser();
        $bob = $this->createUser('bob@example.test', 'Bob');

        $this->client->loginUser($ada);
        $this->checkout();
        $this->assertSame(4900, $this->onlyPurchase()->getPrice(), 'Ada lance son paiement sur la dernière place.');

        // Pas encore payé, mais la place est prise : Bob paierait le prix normal.
        $this->client->loginUser($bob);
        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertSelectorTextContains('main', '79,00');
        $this->assertSelectorTextNotContains('main', '49,00');

        // Ada revient sur la page de paiement : sa réservation ne compte pas contre elle, même session, même prix.
        $this->client->loginUser($ada);
        $this->checkout();
        $this->assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_'.$this->onlyPurchase()->getId(), 303);
        $this->assertSame([], FakePaymentGateway::$expired);

        // Au-delà de la durée de vie de sa session, la place est rendue.
        $this->entityManager()->createQuery('UPDATE '.Purchase::class.' p SET p.createdAt = :old')
            ->execute(['old' => new \DateTimeImmutable(sprintf('-%d seconds', CheckoutSession::LIFETIME + 600))]);
        $this->client->loginUser($bob);
        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertSelectorTextContains('main', '49,00');
    }

    public function testUnWebhookPourUneSessionInconnueResteEnEchecAvecSonMessage(): void
    {
        $this->sendPaidWebhook($this->client, 'cs_inconnue', 7900, eventId: 'evt_inconnu');
        $this->assertResponseStatusCodeSame(500, 'Stripe réessaiera.');

        $this->entityManager()->clear();
        $event = $this->entityManager()->getRepository(StripeEvent::class)->findOneBy(['eventId' => 'evt_inconnu']);
        $this->assertNotNull($event);
        $this->assertFalse($event->isProcessed());
        $this->assertSame('Aucun achat pour la session Stripe « cs_inconnue ».', $event->getError(), 'L\'erreur est gardée pour le rejeu.');
    }

    public function testSiStripeNeRepondPasRienNEstEnregistre(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        FakePaymentGateway::$failing = true;
        $this->checkout();

        $this->assertResponseRedirects('/parcours/payant/acheter');
        $this->assertSame([], $this->entityManager()->getRepository(Purchase::class)->findAll(), 'Pas d\'achat sans session Stripe.');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-danger', 'Rien n\'a été débité');
    }

    /** @return list<Purchase> */
    private function purchasesInOrder(): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(Purchase::class)->findBy([], ['id' => 'ASC']);
    }

    public function testSansRenonciationCocheeLAchatNeDemarrePas(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $this->checkout(waiver: false);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'Cochez la case');
        $this->assertSame([], $this->entityManager()->getRepository(Purchase::class)->findAll());
        $this->assertSame([], FakePaymentGateway::$sessions, 'Stripe n\'est pas appelé.');
    }

    public function testSansAcceptationDesCgvLAchatNeDemarrePas(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $this->checkout(terms: false);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('main', 'Acceptez les conditions générales de vente');
        $this->assertSelectorExists('main label a[href="/cgv"]', 'Les CGV sont à un clic de la case.');
        $this->assertSame([], $this->entityManager()->getRepository(Purchase::class)->findAll());
        $this->assertSame([], FakePaymentGateway::$sessions);
    }

    public function testDesCgvIncompletesEmpechentDeVendre(): void
    {
        $this->setPrice('payant', 7900);
        $before = [$_ENV['LEGAL_MEDIATOR_NAME'] ?? null, $_SERVER['LEGAL_MEDIATOR_NAME'] ?? null];
        // Sans médiateur de la consommation, on ne vend pas à des particuliers.
        $_ENV['LEGAL_MEDIATOR_NAME'] = $_SERVER['LEGAL_MEDIATOR_NAME'] = '';
        try {
            $this->client->loginUser($this->createUser());
            $this->client->request('GET', '/parcours/payant/e2');
            $this->assertSelectorTextContains('.price-box', 'Bientôt disponible');
            $this->client->request('GET', '/parcours/payant/acheter');
            $this->assertResponseRedirects('/parcours/payant');
            $this->client->request('GET', '/cgv');
            $this->assertSelectorTextContains('.legal-missing', 'LEGAL_MEDIATOR_NAME');
        } finally {
            [$_ENV['LEGAL_MEDIATOR_NAME'], $_SERVER['LEGAL_MEDIATOR_NAME']] = $before;
        }
    }

    public function testSansClesStripeLePrixSAfficheEtLAchatEstBientotDisponible(): void
    {
        FakePaymentGateway::$configured = false;
        $this->setPrice('payant', 7900, 4900);
        $this->client->loginUser($this->createUser());

        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('.lp-track-offer', '49,00');
        $this->assertSelectorTextContains('.lp-track-offer', 'Achat bientôt disponible');
        $this->assertSelectorNotExists('a[href="/parcours/payant/acheter"]');

        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('.price-box', 'Bientôt disponible');
        $this->assertSelectorNotExists('a[href="/parcours/payant/acheter"]', 'Pas de bouton vers un paiement impossible.');

        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertResponseRedirects('/parcours/payant');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'bientôt disponible');
        $this->assertSame([], FakePaymentGateway::$sessions);
    }

    public function testUnWebhookMalSigneEstRefuse(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());
        $this->checkout();
        $purchase = $this->onlyPurchase();

        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 7900, secret: 'whsec_pirate');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(PurchaseStatus::Pending, $this->onlyPurchase()->getStatus());
        $this->assertSame([], $this->entityManager()->getRepository(StripeEvent::class)->findAll());
    }

    public function testUnAchatSeDemandeConnecteEtPasPourUnParcoursGratuit(): void
    {
        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertResponseRedirects('/connexion');

        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/parcours/payant/acheter');
        $this->assertResponseRedirects('/parcours/payant', message: 'Sans tarif, le parcours est gratuit.');
    }

    public function testRemboursementParLAdministrateur(): void
    {
        $this->setPrice('payant', 7900);
        $ada = $this->createUser();
        $this->client->loginUser($ada);
        $this->checkout();
        $purchase = $this->onlyPurchase();
        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 7900);

        $admin = $this->createUser('admin@example.test', 'Admin')->setRoles([User::ROLE_ADMIN]);
        $this->entityManager()->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/achats?filters[status][comparison]==&filters[status][value]=paid');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#main', 'Ada');
        $this->assertSelectorTextContains('#main', 'Payé');
        $this->client->request('POST', '/admin/achats/'.$purchase->getId().'/rembourser', server: self::ORIGIN);
        $this->assertResponseRedirects();

        $purchase = $this->onlyPurchase();
        $this->assertSame(PurchaseStatus::Refunded, $purchase->getStatus());
        $this->assertSame(['pi_cs_test_'.$purchase->getId()], FakePaymentGateway::$refunds);
        $access = $this->accessesOf($ada)[0];
        $this->assertNotNull($access->getEndsAt(), 'L\'accès est révoqué.');

        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testUneContestationBancaireRevoqueLAccesJusquACeQuElleSoitGagnee(): void
    {
        $ada = $this->paidPurchase();
        $paymentIntent = 'pi_cs_test_'.$this->onlyPurchase()->getId();

        $this->sendWebhook($this->client, 'charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => $paymentIntent, 'status' => 'needs_response'], 'evt_dispute');
        $this->assertResponseIsSuccessful();
        $this->assertSame(PurchaseStatus::Disputed, $this->onlyPurchase()->getStatus());
        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403, 'Contestation ouverte : l\'accès est révoqué.');

        // Perdue : rien ne change. Gagnée : le paiement reste acquis, l'accès se rouvre.
        $this->sendWebhook($this->client, 'charge.dispute.closed', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => $paymentIntent, 'status' => 'lost'], 'evt_lost');
        $this->assertSame(PurchaseStatus::Disputed, $this->onlyPurchase()->getStatus());
        $this->sendWebhook($this->client, 'charge.dispute.closed', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => $paymentIntent, 'status' => 'won'], 'evt_won');
        $this->assertSame(PurchaseStatus::Paid, $this->onlyPurchase()->getStatus());
        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseIsSuccessful();
    }

    public function testUnRemboursementFaitDepuisStripeRevoqueLAcces(): void
    {
        $ada = $this->paidPurchase();
        $paymentIntent = 'pi_cs_test_'.$this->onlyPurchase()->getId();

        // Remboursement partiel (un geste commercial) : l'accès reste.
        $this->sendWebhook($this->client, 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $paymentIntent, 'refunded' => false, 'amount_refunded' => 1000], 'evt_partiel');
        $this->assertSame(PurchaseStatus::Paid, $this->onlyPurchase()->getStatus());

        $this->sendWebhook($this->client, 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $paymentIntent, 'refunded' => true, 'refunds' => ['data' => [['id' => 're_1']]]], 'evt_total');
        $this->assertResponseIsSuccessful();
        $purchase = $this->onlyPurchase();
        $this->assertSame(PurchaseStatus::Refunded, $purchase->getStatus());
        $this->assertSame('re_1', $purchase->getStripeRefundId());
        $this->client->loginUser($ada);
        $this->client->request('GET', '/parcours/payant/e2');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testLeRemboursementDeLAdministrationRevientParLeWebhookSansRienChanger(): void
    {
        $this->paidPurchase();
        $purchase = $this->onlyPurchase();
        $admin = $this->createUser('admin@example.test', 'Admin')->setRoles([User::ROLE_ADMIN]);
        $this->entityManager()->flush();
        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/achats/'.$purchase->getId().'/rembourser', server: self::ORIGIN);
        $refundedAt = $this->onlyPurchase()->getRefundedAt();

        $this->sendWebhook($this->client, 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_cs_test_'.$purchase->getId(), 'refunded' => true], 'evt_retour');
        $this->assertResponseIsSuccessful();
        $this->assertEquals($refundedAt, $this->onlyPurchase()->getRefundedAt());
        $this->assertNotNull($this->onlyPurchase()->getStripeRefundId(), 'Le numéro de remboursement de l\'administration est gardé.');
    }

    /** Ada achète le parcours « payant », le paiement est confirmé. */
    private function paidPurchase(): User
    {
        $this->setPrice('payant', 7900);
        $ada = $this->createUser();
        $this->client->loginUser($ada);
        $this->checkout();
        $this->sendPaidWebhook($this->client, 'cs_test_'.$this->onlyPurchase()->getId(), 7900);

        return $ada;
    }
}
