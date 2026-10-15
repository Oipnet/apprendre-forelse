<?php

namespace App\Tests\Controller;

use App\Entity\Purchase;
use App\Payment\WithdrawalWaiver;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use App\Tests\PaymentTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'achat d'un parcours traduit, depuis sa page anglaise : confirmation, page Stripe, remerciement et email en
 * anglais, renonciation conservée dans le texte anglais coché. Pack de test « payant », traduit.
 */
final class PurchaseEnglishTest extends WebTestCase
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

    public function testLAchatEnAnglais(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $crawler = $this->client->request('GET', '/en/tracks/payant/buy');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Paid track');
        $this->assertSelectorTextContains('main', 'Lifetime access to the track and its updates.');
        $this->assertSelectorExists('label a[href="/en/terms"]', 'Les CGV acceptées sont celles de la langue de la page.');
        $this->assertSelectorTextContains('main', WithdrawalWaiver::text('en'));

        $form = $crawler->selectButton('Pay')->form();
        $form['purchase_confirmation[termsOfSale]']->tick();
        $form['purchase_confirmation[withdrawalWaiver]']->tick();
        $this->client->submit($form, serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $purchase = $this->onlyPurchase();
        $this->assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_'.$purchase->getId(), 303);
        $this->assertSame('en', $purchase->getLocale());
        $this->assertSame(WithdrawalWaiver::text('en'), $purchase->getWithdrawalWaiverText(), 'Le texte coché, en anglais, est conservé.');
        $this->assertSame([
            'productName' => '“Paid track” track (lifetime access)',
            'successUrl' => 'http://localhost/en/purchase/'.$purchase->getId().'/thanks',
            'cancelUrl' => 'http://localhost/en/tracks/payant',
            'locale' => 'en',
        ], array_intersect_key(FakePaymentGateway::$sessions[0], array_flip(['productName', 'successUrl', 'cancelUrl', 'locale'])));

        $this->client->request('GET', '/en/purchase/'.$purchase->getId().'/thanks');
        $this->assertSelectorTextContains('h1', 'Confirming your payment');

        $this->sendPaidWebhook($this->client, 'cs_test_'.$purchase->getId(), 7900);
        $this->assertResponseStatusCodeSame(204);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'Subject', 'Your access to the “Paid track” track on default');
        $this->assertEmailTextBodyContains($email, 'Start the track: http://localhost/en/tracks/payant');
        $this->assertEmailHtmlBodyContains($email, 'http://localhost/en/terms');
        $this->assertEmailTextBodyContains($email, 'waived your right of withdrawal');

        $this->client->request('GET', '/en/purchase/'.$purchase->getId().'/thanks');
        $this->assertSelectorTextContains('h1', 'Thank you!');
        $this->assertSelectorTextContains('main', 'Paid track');
    }

    public function testLAchatEnFrancaisResteEnFrancais(): void
    {
        $this->setPrice('payant', 7900);
        $this->client->loginUser($this->createUser());

        $crawler = $this->client->request('GET', '/parcours/payant/acheter');
        $form = $crawler->selectButton('Payer')->form();
        $form['purchase_confirmation[termsOfSale]']->tick();
        $form['purchase_confirmation[withdrawalWaiver]']->tick();
        $this->client->submit($form, serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $purchase = $this->onlyPurchase();
        $this->assertSame('fr', $purchase->getLocale());
        $this->assertSame(WithdrawalWaiver::TEXT, $purchase->getWithdrawalWaiverText());
        $this->assertSame('Parcours « Parcours payant » (accès à vie)', FakePaymentGateway::$sessions[0]['productName']);
        $this->assertSame('fr', FakePaymentGateway::$sessions[0]['locale']);
    }

    private function onlyPurchase(): Purchase
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $purchases = $entityManager->getRepository(Purchase::class)->findAll();
        $this->assertCount(1, $purchases);

        return $purchases[0];
    }
}
